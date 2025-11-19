// frontend/src/pages/GamePage.jsx
import React, { useState, useEffect, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";
import useGameSocket from "../hooks/useGameSocket";
import GameTable from "../components/game/GameTable";
import ActionBar from "../components/game/ActionBar";
import GameChatBox from "../components/game/GameChatBox";
import Card from "../components/cards/Card";
import CardBack from "../components/cards/CardBack";
import { parseCard } from "../utils/cardParser";
import "../styles/game.css";

export default function GamePage({ user }) {
  const { tableId } = useParams();
  const navigate = useNavigate();
  const [error, setError] = useState(null);
  const [revealCards, setRevealCards] = useState(false);
  const [showChat, setShowChat] = useState(false);
  const [unreadChatCount, setUnreadChatCount] = useState(0);
  const lastMessageCountRef = useRef(0);

  const {
    gameState,
    sendAction,
    sendChat,
    requestSync,
    disconnect,
    connected,
    reconnecting,
  } = useGameSocket(tableId, (err) => {
    setError(err);
  });

  const messagesEndRef = useRef(null);

  // Track unread chat messages
  useEffect(() => {
    const currentMessageCount = (gameState.chatMessages || []).length;
    if (!showChat && currentMessageCount > lastMessageCountRef.current) {
      // New messages arrived while chat is closed
      setUnreadChatCount(prev => prev + (currentMessageCount - lastMessageCountRef.current));
    }
    lastMessageCountRef.current = currentMessageCount;
  }, [gameState.chatMessages, showChat]);

  // Clear unread count when chat is opened
  useEffect(() => {
    if (showChat) {
      setUnreadChatCount(0);
    }
  }, [showChat]);

  // Redirect if no table ID
  useEffect(() => {
    if (!tableId) navigate("/lobby");
  }, [tableId, navigate]);

  // Prevent closing socket on every refresh
  useEffect(() => {
    const handleBeforeUnload = () => {
      // Let the hook handle reconnects automatically
      // Do NOT call disconnect() here — the backend will debounce reconnect
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => {
      window.removeEventListener("beforeunload", handleBeforeUnload);
    };
  }, []);

  // Only disconnect when user actually leaves table
  const handleLeaveTable = () => {
    disconnect();
    navigate("/lobby");
  };

  const handleAction = (action, amount = 0) => {
    if (!connected) {
      setError("Not connected to game server");
      return;
    }
    setError(null);
    sendAction(action, amount);
  };

  const mySeat = gameState.seats.find((s) => s.seat_no === gameState.mySeat);
  const isMyTurn = gameState.mySeat === gameState.actionSeat;

  if (!tableId) {
    return (
      <div className="game-page">
        <div className="game-error">Invalid table ID</div>
      </div>
    );
  }

  return (
    <div className="game-page">
      {/* ===== HEADER ===== */}
      <div className="game-header">
        <div>
          <h1>Poker Table #{tableId}</h1>
          {Array.isArray(gameState.seats) && gameState.seats.length > 0 && (
            <div className="game-players">
              {gameState.seats.map((seat, idx) => {
                const isMe = user && seat.user_id === user.id;
                const label = seat.username || seat.name || `Seat ${seat.seat_no}`;
                return (
                  <span key={`${seat.user_id || "empty"}-${seat.seat_no}`}>
                    {isMe ? "You" : label}: {label}
                    {idx < gameState.seats.length - 1 && " | "}
                  </span>
                );
              })}
            </div>
          )}
        </div>

        <div className="game-status">
          {reconnecting && <span className="status-reconnecting">Reconnecting...</span>}
          {!connected && !reconnecting && (
            <span className="status-disconnected">Disconnected</span>
          )}
          {connected && <span className="status-connected">Connected</span>}
        </div>

        <button className="leave-button" onClick={handleLeaveTable}>
          Leave Table
        </button>
      </div>

      {/* ===== ERROR DISPLAY ===== */}
      {error && <div className="game-error">Error: {error}</div>}

      {/* ===== LOADING / DISCONNECTED ===== */}
      {!connected && !reconnecting && (
        <div className="game-loading">
          <p>Connecting to game server...</p>
          <button onClick={() => window.location.reload()}>Retry Connection</button>
        </div>
      )}

      {/* ===== MAIN GAME AREA ===== */}
      {connected && (
        <>
          <div className="game-phase">
            Phase: {gameState.phase || "Waiting"}
            {gameState.currentBet > 0 && (
              <span className="current-bet">
                Current Bet: ${gameState.currentBet.toLocaleString()}
              </span>
            )}
          </div>

          <GameTable
            state={{
              ...gameState,
              handSummary: gameState.handSummary,
              showSummary: gameState.showSummary,
            }}
            onAction={handleAction}
            currentUser={user}
          />

          {/* ===== PLAYER'S HOLE CARDS ===== */}
          {mySeat &&
            Array.isArray(gameState.holeCards) &&
            gameState.holeCards.length > 0 && (
              <div className="my-hole-cards">
                <div className="hole-cards-label">Your Cards:</div>
                <div className="hole-cards-container">
                  {gameState.holeCards.map((cardStr, idx) => {
                    const card = parseCard(cardStr);
                    if (!card) return null;
                    return (
                      <div key={idx} className="hole-card">
                        {revealCards ? (
                          <Card suit={card.suit} rank={card.rank} revealed={true} />
                        ) : (
                          <div className="card-back-wrapper">
                            <CardBack />
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
                <button
                  className="flip-cards-btn"
                  onClick={() => setRevealCards(prev => !prev)}
                >
                  {revealCards ? "Hide Cards" : "Show Cards"}
                </button>
              </div>
            )}

          {/* ===== ACTION BAR ===== */}
          {mySeat && gameState.actionSeat && (
              <ActionBar
                legalActions={isMyTurn ? gameState.legalActions : []}
                currentBet={gameState.currentBet}
                myBet={mySeat.bet}
                myStack={mySeat.stack}
                onAction={handleAction}
                disabled={!connected || !isMyTurn || gameState.showSummary}
                showChat={showChat}
                onToggleChat={() => setShowChat(prev => !prev)}
                isMyTurn={isMyTurn}
                unreadChatCount={unreadChatCount}
              />
          )}

          {/* ===== CHAT BOX ===== */}
          {showChat && (
            <GameChatBox
              messages={gameState.chatMessages || []}
              sendChat={sendChat}
              messagesEndRef={messagesEndRef}
            />
          )}
        </>
      )}
    </div>
  );
}

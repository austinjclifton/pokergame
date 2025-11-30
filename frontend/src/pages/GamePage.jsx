// frontend/src/pages/GamePage.jsx
// -----------------------------------------------------------------------------
// GamePage
// The main container for the entire poker table UI.
// Handles socket connection, unread chat tracking, phase display,
// hole cards, action bar, chat panel, and delegates layout components.
//
// NOTE: Pure frontend refactor — no backend contract changes.
// -----------------------------------------------------------------------------

import React, { useState, useEffect, useRef } from "react";
import { useParams, useNavigate } from "react-router-dom";

import useGameSocket from "../hooks/useGameSocket";

// Top-level components
import GameHeader from "../components/game/GameHeader";
import GameTable from "../components/game/GameTable";
import ActionBar from "../components/game/ActionBar";
import GameChatBox from "../components/game/GameChatBox";

// Cards
import Card from "../components/cards/Card";
import CardBack from "../components/cards/CardBack";
import HandResultOverlay from "../components/game/HandResultOverlay";
import MatchEndScreen from "../components/game/MatchEndScreen";

import { parseCard } from "../utils/cardParser";

import "../styles/game.css";

export default function GamePage({ user }) {
  const { tableId } = useParams();
  const navigate = useNavigate();

  // ---------------------------------------------------------
  // Check for match end in localStorage BEFORE loading socket
  // ---------------------------------------------------------
  useEffect(() => {
    if (!tableId) return;
    
    try {
      const cached = localStorage.getItem(`matchSummary_${tableId}`);
      if (cached) {
        // Match has ended, redirect to summary page
        navigate(`/match/${tableId}/summary`, { replace: true });
        return;
      }
    } catch (e) {
      console.error("Failed to check localStorage:", e);
    }
  }, [tableId, navigate]);

  // ---------------------------------------------------------
  // UI State
  // ---------------------------------------------------------
  const [error, setError] = useState(null);
  const [revealCards, setRevealCards] = useState(false);
  const [showChat, setShowChat] = useState(false);
  const [unreadChatCount, setUnreadChatCount] = useState(0);
  const lastMessageCountRef = useRef(0);

  // ---------------------------------------------------------
  // WebSocket + Game State
  // ---------------------------------------------------------
  const {
    gameState,
    sendAction,
    sendChat,
    requestSync,
    disconnect,
    connected,
    reconnecting,
  } = useGameSocket(
    tableId,
    (err) => setError(err),
    (tid) => navigate(`/match/${tid}/summary`, { replace: true })
  );

  const messagesEndRef = useRef(null);

  // ---------------------------------------------------------
  // Unread Chat Tracking
  // ---------------------------------------------------------
  useEffect(() => {
    const messageCount = (gameState.chatMessages || []).length;

    if (!showChat && messageCount > lastMessageCountRef.current) {
      setUnreadChatCount(
        (unread) => unread + (messageCount - lastMessageCountRef.current)
      );
    }

    lastMessageCountRef.current = messageCount;
  }, [gameState.chatMessages, showChat]);

  // Reset unread count when opening chat
  useEffect(() => {
    if (showChat) setUnreadChatCount(0);
  }, [showChat]);

  // ---------------------------------------------------------
  // Navigation Guard (missing tableId)
  // ---------------------------------------------------------
  useEffect(() => {
    if (!tableId) navigate("/lobby");
  }, [tableId, navigate]);

  // ---------------------------------------------------------
  // Prevent socket teardown on refresh
  // ---------------------------------------------------------
  useEffect(() => {
    const handleBeforeUnload = () => {
      // Let hook handle recovery — do NOT disconnect explicitly.
    };
    window.addEventListener("beforeunload", handleBeforeUnload);
    return () => window.removeEventListener("beforeunload", handleBeforeUnload);
  }, []);

  // ---------------------------------------------------------
  // Leave Table Handler
  // ---------------------------------------------------------
  const handleLeaveTable = () => {
    disconnect();
    navigate("/lobby");
  };

  // ---------------------------------------------------------
  // Action Handler Wrapper
  // ---------------------------------------------------------
  const handleAction = (action, amount = 0) => {
    if (!connected) {
      setError("Not connected to game server");
      return;
    }
    setError(null);
    sendAction(action, amount);
  };

  // ---------------------------------------------------------
  // My Seat + Turn Info
  // ---------------------------------------------------------
  const mySeat =
    Array.isArray(gameState.seats) &&
    gameState.seats.find((s) => s.seat_no === gameState.mySeat);

  const isMyTurn = gameState.mySeat === gameState.actionSeat;

  // ---------------------------------------------------------
  // Early Exit (invalid table)
  // ---------------------------------------------------------
  if (!tableId) {
    return (
      <div className="game-page">
        <div className="game-error">Invalid table ID</div>
      </div>
    );
    }

  // =============================================================
  // RENDER PRIORITY OVERRIDES (top-level gating for summaries)
  // =============================================================
  if (connected && gameState.showSummary && gameState.handSummary) {
    return (
      <HandResultOverlay
        summary={gameState.handSummary}
        currentUserSeat={gameState.mySeat}
        onDismiss={() => {
          // The hook likely exposes setShowSummary or similar; 
          // if not I’ll patch that next.
          if (gameState.clearSummary) {
            gameState.clearSummary();
          } else {
            // Fallback: let GameTable's dismiss logic handle it
            requestSync();
          }
        }}
      />
    );
  }

  if (connected && !gameState.showSummary && gameState.matchEnd) {
    return (
      <MatchEndScreen
        winner={gameState.matchEnd.winner}
        loser={gameState.matchEnd.loser}
      />
    );
  }

  // ============================================================================
  // MAIN RENDER
  // ============================================================================
  return (
    <div className="game-page">
      {/* -----------------------------------------------------
         HEADER (cleanly extracted to its own component)
      ------------------------------------------------------ */}
      <GameHeader
        tableId={tableId}
        seats={gameState.seats}
        currentUser={user}
        connected={connected}
        reconnecting={reconnecting}
        onLeave={handleLeaveTable}
      />

      {/* -----------------------------------------------------
         Global Error Display
      ------------------------------------------------------ */}
      {error && <div className="game-error">Error: {error}</div>}

      {/* -----------------------------------------------------
         Connection Lost Screen (before initial connect)
      ------------------------------------------------------ */}
      {!connected && !reconnecting && (
        <div className="game-loading">
          <p>Connecting to game server...</p>
          <button onClick={() => window.location.reload()}>
            Retry Connection
          </button>
        </div>
      )}

      {/* -----------------------------------------------------
         MAIN GAME AREA (only renders when connected)
      ------------------------------------------------------ */}
      {connected && (
        <>
          {/* -------------------- Phase Banner -------------------- */}
          <div className="game-phase">
            Phase: {gameState.phase || "Waiting"}
            {gameState.currentBet > 0 && (
              <span className="current-bet">
                Current Bet: ${gameState.currentBet.toLocaleString()}
              </span>
            )}
          </div>

          {/* -------------------- Table -------------------- */}
          <GameTable
            state={{
              ...gameState,
              handSummary: gameState.handSummary,
              showSummary: gameState.showSummary,
            }}
            onAction={handleAction}
            currentUser={user}
          />

          {/* -------------------- My Hole Cards -------------------- */}
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
                          <Card
                            suit={card.suit}
                            rank={card.rank}
                            revealed={true}
                          />
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
                  onClick={() => setRevealCards((prev) => !prev)}
                >
                  {revealCards ? "Hide Cards" : "Show Cards"}
                </button>
              </div>
            )}

          {/* -------------------- Action Bar -------------------- */}
          {mySeat && gameState.actionSeat && (
            <ActionBar
              legalActions={isMyTurn ? gameState.legalActions : []}
              currentBet={gameState.currentBet}
              myBet={mySeat.bet}
              myStack={mySeat.stack}

              // ========================================================
              // REQUIRED FOR EFFECTIVE STACK — FIXES SLIDER MAX LOGIC
              // ========================================================
              allPlayers={gameState.seats}

              onAction={handleAction}
              disabled={!connected || !isMyTurn || gameState.showSummary}
              showChat={showChat}
              onToggleChat={() => setShowChat((prev) => !prev)}
              isMyTurn={isMyTurn}
              unreadChatCount={unreadChatCount}
            />
          )}

          {/* -------------------- Chat Panel -------------------- */}
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

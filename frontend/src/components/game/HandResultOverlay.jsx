// frontend/src/components/game/HandResultOverlay.jsx
import React from "react";
import Card from "../cards/Card";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

export default function HandResultOverlay({
  summary,
  currentUserSeat,
  onDismiss,
}) {
  if (!summary) return null;

  const { reason, board = [], pot = 0, players = {}, winners = [] } = summary;

  // NEW: hide all cards on fold
  const hideAllCards = reason === "fold";

  // Map seat → Set(bestHandCards)
  const bestHandsBySeat = new Map(
    winners
      .filter((w) => Array.isArray(w.bestHand))
      .map((w) => [Number(w.seat), new Set(w.bestHand)])
  );

  const primaryWinner = winners[0] || null;
  const winningSeat = primaryWinner ? Number(primaryWinner.seat) : null;
  const winningBestSet =
    winningSeat !== null ? bestHandsBySeat.get(winningSeat) || new Set() : new Set();

  const winningHandText =
    primaryWinner?.handDescription || primaryWinner?.reason || null;

  // Sorted seats
  const playerSeats = Object.keys(players)
    .map(Number)
    .sort((a, b) => a - b);

  const getPlayerDelta = (seat) => {
    const w = winners.find((x) => Number(x.seat) === seat);
    return w ? w.amount : -(players[seat]?.bet || 0);
  };

  const getPlayerName = (p, seat, isMe) =>
    isMe ? "You" : p?.username || p?.name || `Seat ${seat}`;

  // POT TEXT SUFFIX
  const getPotSuffix = () => {
    if (!winners.length) return "";
    if (winners.length > 1) return " - Tie!";

    const w = winners[0];
    const seatNum = Number(w.seat);
    const p = players[seatNum];
    const isMe = seatNum === currentUserSeat;

    const name = isMe ? "You" : p?.username || p?.name || `Seat ${seatNum}`;
    const verb = isMe ? "win" : "wins";

    return ` - ${name} ${verb}!`;
  };

  return (
    <div className="hand-result-overlay">
      <div className="hand-result-content">
        {/* HEADER */}
        <div className="hand-result-header">
          <button className="hand-result-close-btn" onClick={onDismiss}>
            ✕
          </button>

          <h2>
            {reason === "fold"
              ? "Hand Ended - Fold"
              : "Hand Ended - Showdown"}
          </h2>

          <div className="hand-result-pot">
            {`Pot: $${pot.toLocaleString()}${getPotSuffix()}`}
          </div>
        </div>

        {/* Winning hand description (text only on fold; normal on showdown) */}
        {winningHandText && (
          <div className="hand-result-winning-hand-main">
            Winning Hand: {winningHandText}
          </div>
        )}

        {/* WINNER’S HOLE CARDS (hidden on fold) */}
        {!hideAllCards && primaryWinner && (
          <div className="hand-result-winning-cards">
            <div className="hand-result-label">Winning Hand:</div>
            <div className="hand-result-cards">
              {players[winningSeat]?.cards?.map((cardStr, idx) => {
                const card = parseCard(cardStr);
                if (!card) return null;

                const highlight = winningBestSet.has(cardStr);

                return (
                  <div
                    key={idx}
                    className={`hand-result-card-wrapper ${
                      highlight ? "best-hand-card" : ""
                    }`}
                  >
                    <div className="hand-result-card">
                      <Card suit={card.suit} rank={card.rank} revealed={true} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* BOARD (hidden on fold) */}
        {!hideAllCards && board.length === 5 && (
          <div className="hand-result-board">
            <div className="hand-result-label">Board:</div>
            <div className="hand-result-cards">
              {board.map((cardStr, idx) => {
                const card = parseCard(cardStr);
                if (!card) return null;

                const highlight = winningBestSet.has(cardStr);

                return (
                  <div
                    key={idx}
                    className={`hand-result-card-wrapper ${
                      highlight ? "best-hand-card" : ""
                    }`}
                  >
                    <div className="hand-result-card">
                      <Card suit={card.suit} rank={card.rank} revealed={true} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* PLAYER RESULT BOXES */}
        <div className="hand-result-players">
          {playerSeats.map((seat) => {
            const p = players[seat];
            const isMe = seat === currentUserSeat;
            const delta = getPlayerDelta(seat);
            const seatBest = bestHandsBySeat.get(seat) || new Set();
            const isWinner = winners.some((w) => Number(w.seat) === seat);

            return (
              <div
                key={seat}
                className={`hand-result-player ${
                  isWinner ? "winner" : ""
                } ${isMe ? "current-user" : ""}`}
              >
                <div className="hand-result-player-header">
                  <div className="hand-result-player-name">
                    {getPlayerName(p, seat, isMe)}
                    {isWinner && <span className="winner-badge">WINNER</span>}
                  </div>

                  <div
                    className={`hand-result-delta ${
                      delta > 0 ? "positive" : "negative"
                    }`}
                  >
                    {delta > 0 ? "+" : ""}${delta.toLocaleString()}
                  </div>
                </div>

                {/* PLAYER HOLE CARDS */}
                <div className="hand-result-player-cards">
                  {!hideAllCards &&
                    p.cards?.map((cardStr, idx) => {
                      const card = parseCard(cardStr);
                      if (!card) return null;

                      const highlight = seatBest.has(cardStr);

                      return (
                        <div
                          key={idx}
                          className={`hand-result-card-wrapper ${
                            highlight ? "best-hand-card" : ""
                          }`}
                        >
                          <div className="hand-result-card">
                            <Card
                              suit={card.suit}
                              rank={card.rank}
                              revealed={true}
                            />
                          </div>
                        </div>
                      );
                    })}
                </div>

                {/* HAND DESCRIPTION (hide if fold) */}
                {!hideAllCards && p.handDescription && (
                  <div className="hand-result-hand-description">
                    {p.handDescription}
                  </div>
                )}

                {p.folded && (
                  <div className="hand-result-folded">FOLDED</div>
                )}

                <div className="hand-result-stack">
                  Final Stack: ${p.stack?.toLocaleString()}
                </div>
              </div>
            );
          })}
        </div>

        {/* DISMISS BUTTON */}
        <button className="hand-result-dismiss-btn" onClick={onDismiss}>
          Continue
        </button>
      </div>
    </div>
  );
}

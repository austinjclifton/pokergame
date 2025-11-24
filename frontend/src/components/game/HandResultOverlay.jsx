// frontend/src/components/game/HandResultOverlay.jsx
import React from "react";
import Card from "../cards/Card";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

/**
 * HandResultOverlay - FINAL VERSION
 *
 * Two-row winner display:
 *   1. Winner's two hole cards (always shown, highlight if part of best 5)
 *   2. The 5 board cards (highlight best-hand cards)
 *
 * Includes a Dismiss button:
 *   - Overlay does NOT auto close
 *   - User must click "Continue"
 *   - Game can still auto-start next hand underneath
 */
export default function HandResultOverlay({
  summary,
  currentUserSeat,
  onDismiss, // <-- NEW
}) {
  if (!summary) return null;

  const {
    reason,
    board = [],
    pot = 0,
    players = {},
    winners = [],
  } = summary;

  // ---------------------------------------------------------
  // Best-hand lookup per seat
  // ---------------------------------------------------------
  const bestHandsBySeat = new Map(
    winners
      .filter((w) => Array.isArray(w.bestHand))
      .map((w) => [Number(w.seat), new Set(w.bestHand)])
  );

  // Main winner info
  const primaryWinner =
    winners.find((w) => w.handDescription || w.reason) ||
    winners[0] ||
    null;

  const winningSeat = primaryWinner ? Number(primaryWinner.seat) : null;
  const winningBestSet =
    winningSeat !== null ? bestHandsBySeat.get(winningSeat) || new Set() : new Set();

  const winningHandText =
    primaryWinner?.handDescription || primaryWinner?.reason || null;

  // Sorted seat list (for result panel)
  const playerSeats = Object.keys(players)
    .map((s) => Number(s))
    .sort((a, b) => a - b);

  const getPlayerDelta = (seat) => {
    const winner = winners.find((w) => Number(w.seat) === seat);
    if (winner) return winner.amount;
    return -(players[seat]?.bet || 0);
  };

  const getPlayerName = (player, seat, isMe) => {
    if (isMe) return "You";
    return player?.username || player?.name || `Seat ${seat}`;
  };

  // ---------------------------------------------------------
  // RENDER
  // ---------------------------------------------------------
  return (
    <div className="hand-result-overlay">
      <div className="hand-result-content">

        {/* HEADER */}
        <div className="hand-result-header">
          <h2>
            {reason === "fold" ? "Hand Ended - Fold" : "Hand Ended - Showdown"}
          </h2>
          <div className="hand-result-pot">Pot: ${pot.toLocaleString()}</div>
        </div>

        {/* Winning hand description text */}
        {winningHandText && (
          <div className="hand-result-winning-hand-main">
            Winning Hand: {winningHandText}
          </div>
        )}

        {/* ==================================================== */}
        {/*  ROW 1 — WINNER HOLE CARDS (always show both)        */}
        {/* ==================================================== */}
        {primaryWinner && (
          <div className="hand-result-winning-cards">
            <div className="hand-result-label">Winning Hand:</div>

            <div className="hand-result-cards">
              {(() => {
                const wp = players[winningSeat];
                if (!wp || !wp.cards) return null;

                return wp.cards.map((cardStr, idx) => {
                  const card = parseCard(cardStr);
                  if (!card) return null;

                  const highlight = winningBestSet.has(cardStr);

                  return (
                    <div
                      key={idx}
                      className={`hand-result-card ${
                        highlight ? "best-hand-card" : ""
                      }`}
                    >
                      <Card suit={card.suit} rank={card.rank} revealed={true} />
                    </div>
                  );
                });
              })()}
            </div>
          </div>
        )}

        {/* ==================================================== */}
        {/*  ROW 2 — FULL BOARD (5 CARDS)                        */}
        {/* ==================================================== */}
        {board.length === 5 && (
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
                    className={`hand-result-card ${
                      highlight ? "best-hand-card" : ""
                    }`}
                  >
                    <Card suit={card.suit} rank={card.rank} revealed={true} />
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* ==================================================== */}
        {/*           PLAYER RESULT BOXES                        */}
        {/* ==================================================== */}
        <div className="hand-result-players">
          {playerSeats.map((seat) => {
            const p = players[seat];
            if (!p) return null;

            const isMe = seat === currentUserSeat;
            const delta = getPlayerDelta(seat);
            const positive = delta > 0;

            const seatBest = bestHandsBySeat.get(seat) || new Set();
            const isWinner = winners.some((w) => Number(w.seat) === seat);

            return (
              <div
                key={seat}
                className={`hand-result-player ${isWinner ? "winner" : ""} ${
                  isMe ? "current-user" : ""
                }`}
              >
                <div className="hand-result-player-header">
                  <div className="hand-result-player-name">
                    {getPlayerName(p, seat, isMe)}
                    {isWinner && <span className="winner-badge">WINNER</span>}
                  </div>

                  <div
                    className={`hand-result-delta ${
                      positive ? "positive" : "negative"
                    }`}
                  >
                    {positive ? "+" : ""}${delta.toLocaleString()}
                  </div>
                </div>

                {/* Player Hole Cards */}
                {p.cards && (
                  <div className="hand-result-player-cards">
                    {p.cards.map((cardStr, idx) => {
                      const card = parseCard(cardStr);
                      if (!card) return null;

                      const used = seatBest.has(cardStr);

                      return (
                        <div
                          key={idx}
                          className={`hand-result-card ${
                            used ? "best-hand-card" : ""
                          }`}
                        >
                          <Card suit={card.suit} rank={card.rank} revealed={true} />
                        </div>
                      );
                    })}
                  </div>
                )}

                {p.handDescription && (
                  <div className="hand-result-hand-description">
                    {p.handDescription}
                  </div>
                )}

                {p.folded && (
                  <div className="hand-result-folded">FOLDED</div>
                )}

                <div className="hand-result-stack">
                  Final Stack: ${p.stack?.toLocaleString() || 0}
                </div>
              </div>
            );
          })}
        </div>

        {/* ==================================================== */}
        {/* WINNER SUMMARY SECTION                               */}
        {/* ==================================================== */}
        {winners.length > 0 && (
          <div className="hand-result-winners">
            {winners.map((w, idx) => {
              const seat = Number(w.seat);
              const p = players[seat];
              const name = p?.username || p?.name || `Seat ${seat}`;

              return (
                <div key={idx} className="hand-result-winner-summary">
                  {name} wins ${w.amount.toLocaleString()}
                  {w.reason && ` with ${w.reason}`}
                </div>
              );
            })}
          </div>
        )}

        {/* ==================================================== */}
        {/* DISMISS BUTTON                                      */}
        {/* ==================================================== */}
        <button className="hand-result-dismiss-btn" onClick={onDismiss}>
          Continue
        </button>

      </div>
    </div>
  );
}

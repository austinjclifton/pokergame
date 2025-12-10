// frontend/src/components/game/HandResultOverlay.jsx
// -----------------------------------------------------------------------------
// HandResultOverlay
// -----------------------------------------------------------------------------
// Displays the end-of-hand summary overlay (fold or showdown).
//
// Delta Logic (Heads-up, production-safe):
// - Prefer using each player's total contribution:
//      Single winner:
//          winnerDelta = pot - contribution[winner]
//          loserDelta  = -contribution[loser]
//      Multiple winners → show 0 (neutral)
//
// - Fallback (when contributions unavailable):
//      winnerDelta = +pot/2
//      loserDelta  = -pot/2
//
// Behavior confirmed for:
//   • 20/20 pot → +20 / -20
//   • 10/20 pot on fold → +10 / -10
//
// This file preserves ALL working logic exactly as intended.
// -----------------------------------------------------------------------------

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

  const {
    reason,
    board = [],
    pot = 0,
    players = {},
    winners = [],
  } = summary;

  const hideAllCards = reason === "fold";
  const HEADS_UP = Object.keys(players).length === 2;

  // ---------------------------------------------------------------------------
  // Winner highlighting map
  // ---------------------------------------------------------------------------
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

  const playerSeats = Object.keys(players)
    .map(Number)
    .filter((n) => !Number.isNaN(n))
    .sort((a, b) => a - b);

  const isSeatWinner = (seat) =>
    winners.some((w) => Number(w.seat) === seat);

  const safeToLocale = (value) =>
    Number.isFinite(Number(value)) ? Number(value).toLocaleString() : "0";

  // ---------------------------------------------------------------------------
  // Contribution helpers
  // ---------------------------------------------------------------------------
  const getContribution = (seat) => {
    const p = players[seat];
    if (!p) return 0;
    return Number(p.contribution ?? p.bet ?? 0) || 0;
  };

  // ---------------------------------------------------------------------------
  // Delta calculation (preserves your exact working behavior)
  // ---------------------------------------------------------------------------
  const getPlayerDelta = (seat) => {
    const p = players[seat];
    if (!p) return 0;

    const seatIsWinner = isSeatWinner(seat);
    const contribution = getContribution(seat);

    // Respect backend authoritative deltas
    if (typeof p.delta === "number") return p.delta;

    // Precise heads-up logic when only one winner
    if (HEADS_UP && winners.length === 1) {
      const [s0, s1] = playerSeats;
      const otherSeat = seat === s0 ? s1 : s0;
      const otherContribution = getContribution(otherSeat);

      // Use contribution-based logic when available
      if (contribution > 0 || otherContribution > 0) {
        return seatIsWinner ? pot - contribution : -contribution;
      }
    }

    // Multiple winners → show neutral
    if (winners.length > 1) {
      return 0;
    }

    // Fallback: pot/2 model
    if (HEADS_UP && winners.length === 1) {
      const half = pot / 2;
      return seatIsWinner ? half : -half;
    }

    return 0;
  };

  // ---------------------------------------------------------------------------
  // Name helpers + pot suffix
  // ---------------------------------------------------------------------------
  const getPlayerName = (p, seat, isMe) =>
    isMe ? "You" : p?.username || p?.name || `Seat ${seat}`;

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

  // =============================================================================
  // RENDER
  // =============================================================================

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
            {`Pot: $${safeToLocale(pot)}${getPotSuffix()}`}
          </div>
        </div>

        {/* WINNER HOLE CARDS */}
        {!hideAllCards && primaryWinner && (
          <div className="hand-result-winning-cards">
            <div className="hand-result-label">
              Winning Hand: {winningHandText || ''}
            </div>

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
          </div>
        )}

        {/* BOARD */}
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

        {/* PLAYER BOXES */}
        <div className="hand-result-players">
          {playerSeats.map((seat) => {
            const p = players[seat];
            const isMe = seat === currentUserSeat;
            const delta = getPlayerDelta(seat);
            const bestSet = bestHandsBySeat.get(seat) || new Set();
            const isWinner = isSeatWinner(seat);

            return (
              <div
                key={seat}
                className={`hand-result-player ${
                  isWinner ? "winner" : ""
                } ${isMe ? "current-user" : ""}`}
              >
                {/* HEADER */}
                <div className="hand-result-player-header">
                  <div className="hand-result-player-name">
                    {getPlayerName(p, seat, isMe)}
                    {isWinner && <span className="winner-badge">WINNER</span>}
                  </div>

                  <div
                    className={`hand-result-delta ${
                      delta > 0 ? "positive" : delta < 0 ? "negative" : ""
                    }`}
                  >
                    {delta > 0 ? "+" : ""}
                    ${safeToLocale(delta)}
                  </div>
                </div>

                {/* HOLE CARDS */}
                <div className="hand-result-player-cards">
                  {!hideAllCards &&
                    p?.cards?.map((cardStr, idx) => {
                      const card = parseCard(cardStr);
                      if (!card) return null;

                      const highlight = bestSet.has(cardStr);

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

                {/* HAND DESCRIPTION */}
                {!hideAllCards && p?.handDescription && (
                  <div className="hand-result-hand-description">
                    {p.handDescription}
                  </div>
                )}

                {/* FOLDED TAG */}
                {p?.folded && (
                  <div className="hand-result-folded">FOLDED</div>
                )}

                {/* FINAL STACK */}
                <div className="hand-result-stack">
                  Final Stack: ${safeToLocale(p?.stack)}
                </div>
              </div>
            );
          })}
        </div>

        {/* CONTINUE BUTTON */}
        <button className="hand-result-dismiss-btn" onClick={onDismiss}>
          Continue
        </button>
      </div>
    </div>
  );
}

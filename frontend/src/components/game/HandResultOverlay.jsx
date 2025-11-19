// frontend/src/components/game/HandResultOverlay.jsx
import React from "react";
import Card from "../cards/Card";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

/**
 * HandResultOverlay - Displays hand summary when a hand ends
 * Shows both players' cards, hand descriptions, and chip deltas
 */
export default function HandResultOverlay({ summary, currentUserSeat }) {
  if (!summary) return null;

  const { reason, board, pot, players, winners } = summary;
  const winnerSeats = new Set((winners || []).map((w) => w.seat));

  // Calculate chip deltas for each player
  const getPlayerDelta = (seat) => {
    const player = players[seat];
    if (!player) return 0;
    
    // Check if this player is a winner
    const winner = winners?.find((w) => w.seat === seat);
    if (winner) {
      // Winner: show amount won
      return winner.amount;
    }
    
    // Loser: show negative of their bet (amount they invested and lost)
    return -(player.bet || 0);
  };

  // Get all player seats
  const playerSeats = Object.keys(players || {}).map(Number).sort((a, b) => a - b);

  return (
    <div className="hand-result-overlay">
      <div className="hand-result-content">
        <div className="hand-result-header">
          <h2>
            {reason === "fold" ? "Hand Ended - Fold" : "Hand Ended - Showdown"}
          </h2>
          <div className="hand-result-pot">Pot: ${pot?.toLocaleString() || 0}</div>
        </div>

        {/* Board Cards */}
        {board && board.length > 0 && (
          <div className="hand-result-board">
            <div className="hand-result-label">Board:</div>
            <div className="hand-result-cards">
              {board.map((cardStr, idx) => {
                const card = parseCard(cardStr);
                if (!card) return null;
                return (
                  <div key={idx} className="hand-result-card">
                    <Card suit={card.suit} rank={card.rank} revealed={true} />
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* Player Results */}
        <div className="hand-result-players">
          {playerSeats.map((seat) => {
            const player = players[seat];
            if (!player) return null;

            const isWinner = winnerSeats.has(seat);
            const isCurrentUser = seat === currentUserSeat;
            const delta = getPlayerDelta(seat);
            const isPositive = delta > 0;

            return (
              <div
                key={seat}
                className={`hand-result-player ${isWinner ? "winner" : ""} ${
                  isCurrentUser ? "current-user" : ""
                }`}
              >
                <div className="hand-result-player-header">
                  <div className="hand-result-player-name">
                    {isCurrentUser ? "You" : `Seat ${seat}`}
                    {isWinner && <span className="winner-badge">WINNER</span>}
                  </div>
                  <div
                    className={`hand-result-delta ${isPositive ? "positive" : "negative"}`}
                  >
                    {isPositive ? "+" : ""}${delta.toLocaleString()}
                  </div>
                </div>

                {/* Player Cards */}
                {player.cards && player.cards.length > 0 && (
                  <div className="hand-result-player-cards">
                    {player.cards.map((cardStr, idx) => {
                      const card = parseCard(cardStr);
                      if (!card) return null;
                      return (
                        <div key={idx} className="hand-result-card">
                          <Card suit={card.suit} rank={card.rank} revealed={true} />
                        </div>
                      );
                    })}
                  </div>
                )}

                {/* Hand Description */}
                {player.handDescription && (
                  <div className="hand-result-hand-description">
                    {player.handDescription}
                  </div>
                )}

                {/* Fold Status */}
                {player.folded && (
                  <div className="hand-result-folded">FOLDED</div>
                )}

                {/* Final Stack */}
                <div className="hand-result-stack">
                  Final Stack: ${player.stack?.toLocaleString() || 0}
                </div>
              </div>
            );
          })}
        </div>

        {/* Winner Summary */}
        {winners && winners.length > 0 && (
          <div className="hand-result-winners">
            {winners.map((winner, idx) => (
              <div key={idx} className="hand-result-winner-summary">
                Seat {winner.seat} wins ${winner.amount.toLocaleString()}
                {winner.reason && ` with ${winner.reason}`}
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}


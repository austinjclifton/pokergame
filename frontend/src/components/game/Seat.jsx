// frontend/src/components/game/Seat.jsx
import React from "react";
import Card from "../cards/Card";
import CardBack from "../cards/CardBack";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

export default function Seat({
  seat_no,
  name,
  stack,
  bet,
  status,
  cards = [],
  isActive = false,
  isCurrentTurn = false,
  isMe = false,
  showBacks = false,
  handDescription = null,
  onFlipCards = null,
  revealCards = false,
}) {
  const parsedCards = cards.map(parseCard).filter(Boolean);

  const isFolded = status === "folded";
  const isAllIn = status === "all_in";
  const isDisconnected = status === "disconnected";

  return (
    <div
      className={`seat seat-${seat_no} 
        ${isFolded ? "folded" : ""} 
        ${isCurrentTurn ? "current-turn" : ""} 
        ${isActive ? "active" : ""} 
        ${isDisconnected ? "disconnected" : ""}`}
    >
      {/* === Compact, thin horizontal layout === */}
      <div className="seat-row">
        <div className="seat-info">
          <div className="seat-name">{name || `Seat ${seat_no}`}</div>

          <div className="seat-subrow">
            <span className="seat-stack">${stack.toLocaleString()}</span>

            {bet > 0 && (
              <span className="seat-bet">Bet: {bet.toLocaleString()}</span>
            )}

            {isAllIn && <span className="seat-allin">ALL IN</span>}
            {isFolded && <span className="seat-folded">FOLDED</span>}
            {isDisconnected && (
              <span className="seat-disconnected">DISCONNECTED</span>
            )}
          </div>
        </div>

        {/* Player’s cards (small, fitted) */}
        {(cards.length > 0 || showBacks) && (
          <div className="seat-cards-row">
            {showBacks
              ? cards.map((_, idx) => (
                  <div key={idx} className="seat-card-small">
                    <CardBack />
                  </div>
                ))
              : parsedCards.map((card, idx) => (
                  <div key={idx} className="seat-card-small">
                    <Card
                      suit={card.suit}
                      rank={card.rank}
                      revealed={!isFolded}
                    />
                  </div>
                ))}
          </div>
        )}
      </div>

      {/* Optional hand description (very small, below name/stack) */}
      {handDescription && (
        <div className="seat-hand-thin">{handDescription}</div>
      )}

      {/* Turn indicator (position stays similar) */}
      {isCurrentTurn && (
        <div className="turn-indicator">
          {isMe ? "Your Turn" : "Their Turn"}
        </div>
      )}
    </div>
  );
}

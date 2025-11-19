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
      className={`seat seat-${seat_no} ${isFolded ? "folded" : ""} ${
        isCurrentTurn ? "current-turn" : ""
      } ${isActive ? "active" : ""} ${isDisconnected ? "disconnected" : ""}`}
    >
      <div className="seat-content">
        <div className="seat-name">{name || `Seat ${seat_no}`}</div>
        <div className="seat-stack">${stack.toLocaleString()}</div>
        <div className="seat-bet" style={{ minHeight: '1.2rem', opacity: bet > 0 ? 1 : 0 }}>
          {bet > 0 ? `Bet: ${bet.toLocaleString()}` : '\u00A0'}
        </div>
        {isAllIn && <div className="seat-allin">ALL IN</div>}
        {isFolded && <div className="seat-folded">FOLDED</div>}
        {isDisconnected && <div className="seat-disconnected">DISCONNECTED</div>}
        {handDescription && (
          <div className="seat-hand">{handDescription}</div>
        )}
      </div>
      {(cards.length > 0 || showBacks) && (
        <div className="seat-cards">
          {showBacks
            ? cards.map((_, idx) => (
                <div key={idx} className="seat-card">
                  <CardBack />
                </div>
              ))
            : parsedCards.map((card, idx) => (
                <div key={idx} className="seat-card">
                  <Card
                    suit={card.suit}
                    rank={card.rank}
                    revealed={!isFolded}
                  />
                </div>
              ))
          }
        </div>
      )}
      {isCurrentTurn && (
        <div className="turn-indicator">
          {isMe ? "Your Turn" : "Their Turn"}
        </div>
      )}
    </div>
  );
}


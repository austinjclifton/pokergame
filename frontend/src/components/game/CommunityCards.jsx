// frontend/src/components/game/CommunityCards.jsx
import React from "react";
import Card from "../cards/Card";
import CardBack from "../cards/CardBack";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

export default function CommunityCards({ cards = [] }) {
  const parsedCards = cards.map(parseCard).filter(Boolean);

  return (
    <div className="community-cards">
      {parsedCards.length === 0 ? (
        <div className="community-placeholder">Waiting for cards...</div>
      ) : (
        <div className="community-cards-container">
          {parsedCards.map((card, idx) => (
            <div key={idx} className="community-card-wrapper">
              <Card suit={card.suit} rank={card.rank} revealed={true} />
            </div>
          ))}
          {/* Show placeholders for remaining cards */}
          {parsedCards.length < 5 && (
            <>
              {Array.from({ length: 5 - parsedCards.length }).map((_, idx) => (
                <div key={`placeholder-${idx}`} className="community-card-wrapper">
                  <CardBack width={100} height={150} />
                </div>
              ))}
            </>
          )}
        </div>
      )}
    </div>
  );
}


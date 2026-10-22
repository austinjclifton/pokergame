// frontend/src/components/game/CommunityCards.jsx
// -----------------------------------------------------------------------------
// CommunityCards
// Renders the flop/turn/river cards in a simple horizontal layout.
// Cards start face down and auto-flip when revealed based on game phase.
// -----------------------------------------------------------------------------

import React, { useState, useEffect, useRef } from "react";
import Card from "../cards/Card";
import CardBack from "../cards/CardBack";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

function revealedCardCount(phase, availableCards) {
  const phaseUpper = (phase || "PREFLOP").toString().toUpperCase();
  if (phaseUpper === "PREFLOP") return 0;
  if (phaseUpper === "FLOP") return 3;
  if (phaseUpper === "TURN") return 4;
  if (phaseUpper === "RIVER") return 5;
  return availableCards;
}

export default function CommunityCards({ cards = [], phase = "PREFLOP" }) {
  const parsedCards = cards.map(parseCard).filter(Boolean);
  const targetRevealedCount = revealedCardCount(phase, parsedCards.length);

  const [revealedCards, setRevealedCards] = useState(new Set());
  const [flippingCards, setFlippingCards] = useState(new Set());
  const revealedCountRef = useRef(0);

  // Auto-flip cards when phase changes (including on first mount)
  useEffect(() => {
    const timers = [];
    const currentRevealedCount = revealedCountRef.current;
    
    // Reset if phase went backwards (new hand started)
    if (targetRevealedCount < currentRevealedCount) {
      revealedCountRef.current = 0;
      setRevealedCards(new Set());
      setFlippingCards(new Set());
      return undefined;
    }

    const cardsToFlip = [];
    const availableTarget = Math.min(targetRevealedCount, parsedCards.length);
    for (let i = currentRevealedCount; i < availableTarget; i++) {
      cardsToFlip.push(i);
    }

    cardsToFlip.forEach((cardIdx, delayIdx) => {
      timers.push(setTimeout(() => {
        setFlippingCards((previous) => new Set([...previous, cardIdx]));
        timers.push(setTimeout(() => {
          setFlippingCards((previous) => {
            const next = new Set(previous);
            next.delete(cardIdx);
            return next;
          });
          setRevealedCards((previous) => new Set([...previous, cardIdx]));
          revealedCountRef.current = Math.max(revealedCountRef.current, cardIdx + 1);
        }, 400));
      }, delayIdx * 400));
    });

    return () => timers.forEach(clearTimeout);
  }, [phase, parsedCards.length, targetRevealedCount]);

  // Always render 5 card slots
  const cardSlots = Array.from({ length: 5 }, (_, idx) => {
    const card = parsedCards[idx] || null;
    const isRevealed = revealedCards.has(idx) && card !== null;
    const isFlipping = flippingCards.has(idx);

    return { idx, card, isRevealed, isFlipping };
  });

  return (
    <div className="community-cards">
      <div className="community-cards-container">
        {cardSlots.map(({ idx, card, isRevealed, isFlipping }) => (
          <div 
            key={idx} 
            className={`community-card-wrapper flip-card ${isFlipping ? 'flipping' : ''} ${isRevealed ? 'revealed' : ''}`}
          >
            <div className="card-flip-inner">
              <div className="card-flip-front">
                <div className="card-back-wrapper">
                  <CardBack width={100} height={150} />
                </div>
              </div>
              <div className="card-flip-back">
                {card ? (
                  <Card suit={card.suit} rank={card.rank} revealed={true} />
                ) : (
                  <CardBack width={100} height={150} />
                )}
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

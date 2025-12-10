// frontend/src/components/game/CommunityCards.jsx
// -----------------------------------------------------------------------------
// CommunityCards
// Renders the flop/turn/river cards in a simple horizontal layout.
// Cards start face down and auto-flip when revealed based on game phase.
// -----------------------------------------------------------------------------

import React, { useState, useEffect } from "react";
import Card from "../cards/Card";
import CardBack from "../cards/CardBack";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

export default function CommunityCards({ cards = [], phase = "PREFLOP" }) {
  const parsedCards = cards.map(parseCard).filter(Boolean);
  
  // Determine how many cards should be revealed based on phase
  const getRevealedCount = () => {
    const phaseUpper = (phase || "PREFLOP").toString().toUpperCase();
    if (phaseUpper === "PREFLOP") return 0;
    if (phaseUpper === "FLOP") return 3;
    if (phaseUpper === "TURN") return 4;
    if (phaseUpper === "RIVER") return 5;
    return parsedCards.length; // Fallback: show all if phase unknown
  };

  const [revealedCards, setRevealedCards] = useState(new Set());
  const [flippingCards, setFlippingCards] = useState(new Set());
  const [previousPhase, setPreviousPhase] = useState(null);

  // Auto-flip cards when phase changes (including on first mount)
  useEffect(() => {
    const newRevealedCount = getRevealedCount();
    const currentRevealedCount = revealedCards.size;
    
    // Reset if phase went backwards (new hand started)
    if (newRevealedCount < currentRevealedCount) {
      setRevealedCards(new Set());
      setFlippingCards(new Set());
      setPreviousPhase(phase);
      return;
    }
    
    // Trigger animation if phase changed OR if we need to reveal cards (including first mount)
    const phaseChanged = phase !== previousPhase;
    const needsReveal = newRevealedCount > currentRevealedCount;
    
    if (phaseChanged || needsReveal) {
      // Cards need to be revealed - flip them with staggered timing
      const cardsToFlip = [];
      for (let i = currentRevealedCount; i < newRevealedCount && i < parsedCards.length; i++) {
        cardsToFlip.push(i);
      }
      
      // Only animate if there are cards to flip
      if (cardsToFlip.length > 0) {
        // Flip each card with a delay for visual effect - each card flips individually
        cardsToFlip.forEach((cardIdx, delayIdx) => {
          const delay = delayIdx * 400; // 400ms delay between each card for clearer individual flips
          setTimeout(() => {
            // Start flip animation
            setFlippingCards(prev => new Set([...prev, cardIdx]));
            // Mark as revealed after flip completes
            setTimeout(() => {
              setFlippingCards(prev => {
                const next = new Set(prev);
                next.delete(cardIdx);
                return next;
              });
              // Mark this card as revealed
              setRevealedCards(prev => new Set([...prev, cardIdx]));
            }, 400); // Match flip animation duration
          }, delay);
        });
      }
      
      setPreviousPhase(phase);
    }
  }, [phase, cards.length, parsedCards.length]);

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

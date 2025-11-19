// frontend/src/components/game/PotDisplay.jsx
import React from "react";
import "../../styles/game.css";

export default function PotDisplay({ pot = 0, pots = [] }) {
  // Use pots array if available, otherwise use single pot
  const displayPots = pots.length > 0 ? pots : [{ amount: pot }];
  const totalPot = displayPots.reduce((sum, p) => sum + (p.amount || 0), 0);

  return (
    <div className="pot-display">
      <div className="pot-label">Pot</div>
      <div className="pot-amount">${totalPot.toLocaleString()}</div>
      {displayPots.length > 1 && (
        <div className="side-pots">
          {displayPots.map((p, idx) => (
            <div key={idx} className="side-pot">
              Side Pot {idx + 1}: ${p.amount.toLocaleString()}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}


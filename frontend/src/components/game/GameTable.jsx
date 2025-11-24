// frontend/src/components/game/GameTable.jsx
import React, { useState, useEffect } from "react";
import Seat from "./Seat";
import CommunityCards from "./CommunityCards";
import PotDisplay from "./PotDisplay";
import HandResultOverlay from "./HandResultOverlay";
import "../../styles/game.css";

export default function GameTable({ state, currentUser }) {
  const {
    seats = [],
    community = [],
    pot = 0,
    pots = [],
    actionSeat,
    mySeat,
    handSummary,
    showSummary, // still passed through but NOT trusted for overlay visibility
  } = state;

  // ============================================================================
  // LOCAL OVERLAY VISIBILITY — the fix to stop auto-hiding
  // ============================================================================
  const [forceShowOverlay, setForceShowOverlay] = useState(false);

  // When a new summary arrives → show overlay
  useEffect(() => {
    if (handSummary) {
      setForceShowOverlay(true);
    }
  }, [handSummary]);

  // Click handler from overlay
  const handleDismissSummary = () => {
    setForceShowOverlay(false);
  };

  // ============================================================================
  // Build Seat Props
  // ============================================================================
  const buildSeatProps = (seat) => {
    if (!seat) return null;
    const amISeat = seat.seat_no === mySeat;
    const isMe = currentUser && seat.user_id === currentUser.id;

    return {
      seat_no: seat.seat_no,
      name: isMe
        ? `${seat.username || seat.name} (You)`
        : seat.username || seat.name,
      stack: seat.stack,
      bet: seat.bet,
      status: seat.status,
      cards: amISeat ? seat.cards || [] : [],
      isActive: seat.status === "active" && !isMe,
      isMe: isMe || amISeat,
      isCurrentTurn: seat.seat_no === actionSeat,
      handDescription: seat.handDescription,
    };
  };

  // Stable order
  const orderedSeats = [...seats].sort((a, b) => a.seat_no - b.seat_no);

  // ============================================================================
  // RENDER
  // ============================================================================
  return (
    <div className="game-table-container-simple">

      {/* ----------------- PLAYER ROW ----------------- */}
      <div className="two-player-row">
        {orderedSeats.map((seat) => {
          const props = buildSeatProps(seat);
          if (!props) return null;

          return (
            <div key={seat.seat_no} className="seat-wrapper-inline">
              <Seat {...props} />
            </div>
          );
        })}
      </div>

      {/* ----------------- BOARD ----------------- */}
      <div className="center-board-area">
        <PotDisplay pot={pot} pots={pots} />
        <CommunityCards cards={community} />
      </div>

      {/* ----------------- RESULT OVERLAY ----------------- */}
      {forceShowOverlay && handSummary && (
        <HandResultOverlay
          summary={handSummary}
          currentUserSeat={mySeat}
          onDismiss={handleDismissSummary}
        />
      )}
    </div>
  );
}

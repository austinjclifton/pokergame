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

    // One-hand summary (used by overlay)
    handSummary,
  } = state;

  // =============================================================================
  // HAND OVERLAY VISIBILITY (client controls dismissal)
  // =============================================================================
  const [forceShowOverlay, setForceShowOverlay] = useState(false);

  useEffect(() => {
    if (handSummary) {
      setForceShowOverlay(true);
    }
  }, [handSummary]);

  const dismissHandOverlay = () => setForceShowOverlay(false);

  // =============================================================================
  // SEAT PROP BUILDER
  // =============================================================================
  const buildSeatProps = (seat) => {
    if (!seat) return null;

    const isMe = currentUser && seat.user_id === currentUser.id;
    const amISeat = seat.seat_no === mySeat;

    return {
      seat_no: seat.seat_no,
      name: isMe
        ? `${seat.username || seat.name} (You)`
        : seat.username || seat.name,
      stack: seat.stack,
      bet: seat.bet,
      status: seat.status,
      isMe,
      isCurrentTurn: seat.seat_no === actionSeat,
      cards: amISeat ? seat.cards || [] : [],
      handDescription: seat.handDescription,
    };
  };

  const orderedSeats = [...seats].sort((a, b) => a.seat_no - b.seat_no);

  // =============================================================================
  // NORMAL TABLE RENDER
  // =============================================================================
  return (
    <div className="game-table-container-simple">
      {/* ----------------- PLAYERS ----------------- */}
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

      {/* ----------------- HAND RESULT OVERLAY ----------------- */}
      {forceShowOverlay && handSummary && (
        <HandResultOverlay
          summary={handSummary}
          currentUserSeat={mySeat}
          onDismiss={dismissHandOverlay}
        />
      )}
    </div>
  );
}

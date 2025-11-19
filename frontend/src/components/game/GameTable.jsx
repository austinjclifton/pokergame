// frontend/src/components/game/GameTable.jsx
import React, { useState } from "react";
import Seat from "./Seat";
import CommunityCards from "./CommunityCards";
import PotDisplay from "./PotDisplay";
import HandResultOverlay from "./HandResultOverlay";
import "../../styles/game.css";

export default function GameTable({ state, onAction, currentUser }) {
  const { 
    seats = [], 
    community = [], 
    pot = 0, 
    pots = [], 
    actionSeat, 
    mySeat,
    handSummary,
    showSummary,
  } = state;

  return (
    <div className="game-table-container">
      <div className="game-table">
        {/* Seats positioned at top */}
        <div className="seats-container">
          {seats.map((seat) => {
            const isCurrentTurn = seat.seat_no === actionSeat;
            const isMySeat = seat.seat_no === mySeat;
            const isMe = currentUser && seat.user_id === currentUser.id;
            const displayName = isMe 
              ? `${seat.username || seat.name} (You)`
              : (seat.username || seat.name);
            
            return (
              <div key={seat.seat_no} className="seat-wrapper">
                <Seat
                  seat_no={seat.seat_no}
                  name={displayName}
                  stack={seat.stack}
                  bet={seat.bet}
                  status={seat.status}
                  cards={isMySeat ? (seat.cards || []) : []}
                  showBacks={false}
                  isActive={seat.status === "active" && !isMe}
                  isMe={isMe || isMySeat}
                  isCurrentTurn={isCurrentTurn}
                  handDescription={seat.handDescription}
                />
              </div>
            );
          })}
        </div>

        {/* Center area: Community cards and pot */}
        <div className="table-center">
          <PotDisplay pot={pot} pots={pots} />
          <CommunityCards cards={community} />
        </div>
      </div>

      {/* Hand Result Overlay */}
      {showSummary && handSummary && (
        <HandResultOverlay
          summary={handSummary}
          currentUserSeat={mySeat}
        />
      )}
    </div>
  );
}


import React from "react";
import "../../styles/game.css";

/**
 * GameHeader
 *
 * A clean, future-proof header for the Poker Table screen.
 * 
 * Responsibilities:
 *  - Display table ID
 *  - Display connection status (Connected / Reconnecting / Disconnected)
 *    merged elegantly into the title line
 *  - Display a clean player list
 *  - Leave Table button
 *
 * This component contains **no socket logic**, **no state**, and
 * no backend assumptions. It is purely presentational.
 */
export default function GameHeader({
  tableId,
  seats = [],
  currentUser,
  connected,
  reconnecting,
  onLeave,
}) {
  // -----------------------------
  // Connection Status Label
  // -----------------------------
  let statusText = "";
  let statusClass = "";

  if (reconnecting) {
    statusText = "Reconnecting…";
    statusClass = "status-reconnecting";
  } else if (!connected) {
    statusText = "Disconnected";
    statusClass = "status-disconnected";
  } else {
    statusText = "Connected";
    statusClass = "status-connected";
  }

  // -----------------------------
  // Player List (clean + readable)
  // -----------------------------
  const playerList =
    seats
        .filter((s) => s && s.seat_no !== undefined)
        .map((seat) => {
        const isMe = currentUser && seat.user_id === currentUser.id;

        const name = isMe
            ? "You"
            : (seat.username || seat.name || `Player`);

        return `Seat ${seat.seat_no}: ${name}`;
        })
        .join("  •  ");

  return (
    <div className="game-header">

      {/* === LEFT SECTION === */}
      <div className="game-header-left">
        <h1 className="game-header-title">
          Poker Table #{tableId} —{" "}
          <span className={statusClass}>{statusText}</span>
        </h1>

        {playerList && (
          <div className="game-header-players">
            {playerList}
          </div>
        )}
      </div>

      {/* === RIGHT SECTION === */}
      <div className="game-header-right">
        <button className="leave-button" onClick={onLeave}>
          Leave Table
        </button>
      </div>
    </div>
  );
}

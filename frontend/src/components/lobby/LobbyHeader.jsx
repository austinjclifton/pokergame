// frontend/src/components/lobby/LobbyHeader.jsx
// -----------------------------------------------------------------------------
// LobbyHeader
// Displays:
//   • App title
//   • Realtime user counts (in lobby, in game, total)
//   • Challenge panel toggle
//   • Logout button
//
// This component is intentionally stateless — it renders only what is passed in.
// All logic for presence, sorting, and filtering stays in LobbyPage.
// -----------------------------------------------------------------------------

import React from "react";

export default function LobbyHeader({
  challenges,
  showChallenges,
  setShowChallenges,
  handleLogout,
  players,
}) {
  // ---------------------------------------------------------------------------
  // USER COUNT COMPUTATION (authoritative + future-proof)
  // ---------------------------------------------------------------------------
  // "In Lobby" → users who are explicitly marked "online"
  const inLobbyCount = players.filter((p) => p.status === "online").length;

  // "In Game" → users marked "in_game"
  const inGameCount = players.filter((p) => p.status === "in_game").length;

  // Total users = full player list length
  const totalCount = players.length;

  // ---------------------------------------------------------------------------
  // RENDER
  // ---------------------------------------------------------------------------
  return (
    <div className="lobby-header">
      {/* Left side: Title + user stats */}
      <div className="header-left">
        <h1 className="lobby-title">PokerGame Lobby</h1>

        <div className="user-counts">
          {/* In Lobby count */}
          <span className="count-item">
            <span className="count-label">In Lobby:</span>
            <span className="count-value">{inLobbyCount}</span>
          </span>

          {/* In Game count */}
          <span className="count-item">
            <span className="count-label">In Game:</span>
            <span className="count-value">{inGameCount}</span>
          </span>

          {/* Total user count */}
          <span className="count-item">
            <span className="count-label">Total:</span>
            <span className="count-value">{totalCount}</span>
          </span>
        </div>
      </div>

      {/* Right side: Buttons */}
      <div className="header-buttons">
        {/* Toggle challenge panel */}
        <button
          className="challenges-button"
          onClick={() => setShowChallenges(!showChallenges)}
        >
          Challenges ({challenges.length})
        </button>

        {/* Logout */}
        <button className="logout-button" onClick={handleLogout}>
          Logout
        </button>
      </div>
    </div>
  );
}

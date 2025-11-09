// frontend/src/components/lobby/LobbyHeader.jsx
import React from "react";

/**
 * LobbyHeader
 * Displays title, challenge toggle button, user counts, and logout control.
 */
export default function LobbyHeader({
  challenges,
  showChallenges,
  setShowChallenges,
  handleLogout,
  players,
}) {
  // Calculate user counts
  const onlineCount = players.filter((p) => p.status === "online" || !p.status).length;
  const inGameCount = players.filter((p) => p.status === "in_game").length;
  const totalCount = players.length;

  return (
    <div className="lobby-header">
      <div className="header-left">
        <h1 className="lobby-title">PokerGame Lobby</h1>
        <div className="user-counts">
          <span className="count-item">
            <span className="count-label">Online:</span>
            <span className="count-value">{onlineCount}</span>
          </span>
          <span className="count-item">
            <span className="count-label">In Game:</span>
            <span className="count-value">{inGameCount}</span>
          </span>
          <span className="count-item">
            <span className="count-label">Total:</span>
            <span className="count-value">{totalCount}</span>
          </span>
        </div>
      </div>

      <div className="header-buttons">
        <button
          className="challenges-button"
          onClick={() => setShowChallenges(!showChallenges)}
        >
          Challenges ({challenges.length})
        </button>

        <button className="logout-button" onClick={handleLogout}>
          Logout
        </button>
      </div>
    </div>
  );
}

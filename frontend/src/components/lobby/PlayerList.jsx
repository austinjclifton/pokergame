// src/components/lobby/PlayerList.jsx
import React from "react";

export default function PlayerList({
  players,
  user,
  socketRef,
  challenges = [],
  pendingChallenge,
  setPendingChallenge,
  navigate
}) {

  // --- Authoritative outgoing challenge from server ---
  const getOutgoingChallenge = (playerId) =>
    challenges.find(
      (c) =>
        c.from_user_id === user.id &&
        c.to_user_id === playerId &&
        c.status === "pending"
    );

  // --- Server-detected incoming challenge ---
  const hasIncomingChallenge = (playerId) =>
    challenges.some(
      (c) =>
        c.to_user_id === user.id &&
        c.from_user_id === playerId &&
        c.status === "pending"
    );

  // --- Optimistic outgoing challenge (client-only) ---
  const isPendingTo = (playerId) =>
    pendingChallenge?.opponent_id === playerId;

  // --- User has ANY outgoing challenge (to ANY player) ---
  const hasAnyOutgoing =
    pendingChallenge !== null ||
    challenges.some(
      (c) => c.from_user_id === user.id && c.status === "pending"
    );

  const sendChallenge = (toUserId) => {
    if (!socketRef.current) return;

    const target = players.find((p) => p.id === toUserId);

    // Optimistic: temporary pending until server sends challenge_sent
    setPendingChallenge({
      id: null,
      opponent: target?.username ?? `User#${toUserId}`,
      opponent_id: toUserId,
      created_at: new Date().toISOString()
    });

    socketRef.current.send(
      JSON.stringify({ type: "challenge", to_user_id: toUserId })
    );
  };

  const cancelChallenge = (challengeId) => {
    if (!socketRef.current) return;

    // Immediately clear optimistic state
    setPendingChallenge(null);

    socketRef.current.send(
      JSON.stringify({
        type: "challenge_cancel",
        challenge_id: challengeId
      })
    );
  };

  const handleJoin = (tableId) => {
    if (navigate && tableId) navigate(`/game/${tableId}`);
  };

  const currentUserActiveTable =
    players.find((p) => p.id === user.id)?.active_table_id ?? null;

  return (
    <div className="lobby-container">
      <ul className="player-list">
        {players.length === 0 ? (
          <p style={{ color: "#aaa" }}>No players online yet.</p>
        ) : (
          players.map((p) => {
            const isSelf = p.id === user.id;
            const incoming = hasIncomingChallenge(p.id);
            const outgoing = getOutgoingChallenge(p.id);
            const pendingToPlayer = isPendingTo(p.id);
            const playerActiveTable = p.active_table_id ?? null;

            const bothInSameGame =
              currentUserActiveTable &&
              playerActiveTable &&
              currentUserActiveTable === playerActiveTable;

            // --- Self row: no button ever ---
            if (isSelf) {
              return (
                <li key={p.id} className="player-item">
                  <span>
                    {p.username}
                    <span style={{ color: "#aaa", fontSize: "0.9em", marginLeft: 5 }}>
                      (you)
                    </span>
                  </span>
                </li>
              );
            }

            // ---- Determine button state ----
            let buttonText = "Challenge";
            let buttonClass = "challenge-button";
            let buttonTitle = "";
            let onClick = () => sendChallenge(p.id);
            let disabled = false;

            if (bothInSameGame) {
              buttonText = "Join Table";
              buttonClass = "join-button";
              buttonTitle = `Rejoin table #${playerActiveTable}`;
              onClick = () => handleJoin(playerActiveTable);
            } else if (incoming) {
              buttonText = "Challenged You";
              disabled = true;
            } else if (outgoing || pendingToPlayer) {
              buttonText = "Cancel";
              buttonClass = "cancel-button";
              const challengeId = outgoing?.id || pendingChallenge?.id;
              onClick = () => challengeId && cancelChallenge(challengeId);
            } else if (hasAnyOutgoing) {
              buttonTitle = "You already have a pending challenge.";
              disabled = true;
            }

            return (
              <li key={p.id} className="player-item">
                <span>{p.username}</span>
                <button
                  className={buttonClass}
                  onClick={onClick}
                  disabled={disabled}
                  title={buttonTitle}
                >
                  {buttonText}
                </button>
              </li>
            );
          })
        )}
      </ul>
    </div>
  );
}

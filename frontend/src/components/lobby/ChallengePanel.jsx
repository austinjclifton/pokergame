// frontend/src/components/lobby/ChallengePanel.jsx
import React from "react";

/**
 * ChallengePanel
 * Renders current challenge list with accept/decline controls.
 */
export default function ChallengePanel({
  challenges,
  user,
  pending,
  setPending,
  socketRef, // Add socketRef prop to send WebSocket messages
}) {
  const handleRespond = (id, action) => {
    if (!socketRef?.current) return;
    
    // Send challenge response via WebSocket
    socketRef.current.send(JSON.stringify({
      type: "challenge_response",
      challenge_id: id,
      action: action,
    }));
    
    // Optimistically update pending state
    setPending((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  const handleCancel = (id) => {
    if (!socketRef?.current) return;
    
    // Send challenge cancel via WebSocket
    socketRef.current.send(JSON.stringify({
      type: "challenge_cancel",
      challenge_id: id,
    }));
    
    // Optimistically update pending state
    setPending((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  return (
    <div className="challenges-panel">
      <h3>Challenges</h3>
      <div className="challenges-list">
        {challenges.length === 0 ? (
          <p style={{ color: "#aaa" }}>No pending challenges.</p>
        ) : (
          challenges.map((c) => (
            <div key={c.id} className="challenge-item">
              <div className="challenge-info">
                <span className="challenge-text">
                  {c.is_from_me ? (
                    <>
                      You challenged <strong>{c.to_username}</strong>
                    </>
                  ) : (
                    <>
                      <strong>{c.from_username}</strong> challenged you
                    </>
                  )}
                </span>
                <span className="challenge-time">
                  {new Date(c.created_at).toLocaleString()}
                </span>
              </div>

              {/* Actions: Accept/Decline if challenged, Cancel if sender */}
              {c.is_to_me && (
                <div className="challenge-actions">
                  <button
                    className="accept-button"
                    onClick={() => handleRespond(c.id, "accept")}
                  >
                    Accept
                  </button>
                  <button
                    className="decline-button"
                    onClick={() => handleRespond(c.id, "decline")}
                  >
                    Decline
                  </button>
                </div>
              )}
              {c.is_from_me && (
                <div className="challenge-actions">
                  <button
                    className="cancel-button"
                    onClick={() => handleCancel(c.id)}
                  >
                    Cancel
                  </button>
                </div>
              )}
            </div>
          ))
        )}
      </div>
    </div>
  );
}

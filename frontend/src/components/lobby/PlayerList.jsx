export default function PlayerList({ players, user, socketRef, challenges = [] }) {
    const sendChallenge = (id) => {
      socketRef.current?.send(JSON.stringify({ type: "challenge", to_user_id: id }));
    };

    const cancelChallenge = (challengeId) => {
      if (!socketRef.current) return;
      socketRef.current.send(JSON.stringify({ type: "challenge_cancel", challenge_id: challengeId }));
    };

    const hasIncomingChallenge = (playerId) => {
      return challenges.some(
        (c) => c.from_user_id === playerId && c.to_user_id === user.id && c.status === 'pending'
      );
    };

    const getOutgoingChallenge = (playerId) => {
      return challenges.find(
        (c) => c.from_user_id === user.id && c.to_user_id === playerId && c.status === 'pending'
      );
    };

    return (
      <div className="lobby-container">
        <ul className="player-list">
          {players.length === 0 ? (
            <p style={{ color: "#aaa" }}>No players online yet.</p>
          ) : (
            players.map((p) => {
              const hasIncoming = hasIncomingChallenge(p.id);
              const outgoingChallenge = getOutgoingChallenge(p.id);
              const hasOutgoing = !!outgoingChallenge;
              
              let buttonText = "Challenge";
              let buttonTitle = "";
              let buttonClass = "challenge-button";
              let onClickHandler = () => sendChallenge(p.id);
              
              if (hasIncoming) {
                buttonText = "Challenged You";
                buttonTitle = "This player has challenged you. Respond to their challenge first.";
                buttonClass = "challenge-button";
              } else if (hasOutgoing) {
                buttonText = "Cancel";
                buttonTitle = "Cancel your challenge to this player.";
                buttonClass = "cancel-button";
                onClickHandler = () => cancelChallenge(outgoingChallenge.id);
              }
              
              return (
                <li key={p.id} className="player-item">
                  <span>{p.username}</span>
                  {p.username !== user.username && (
                    <button
                      className={buttonClass}
                      onClick={onClickHandler}
                      disabled={hasIncoming}
                      title={buttonTitle}
                    >
                      {buttonText}
                    </button>
                  )}
                </li>
              );
            })
          )}
        </ul>
      </div>
    );
  }
  
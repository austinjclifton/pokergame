// frontend/src/components/game/ActionBar.jsx
import React, { useState, useEffect } from "react";
import "../../styles/game.css";

export default function ActionBar({
  legalActions = [],
  currentBet = 0,
  myBet = 0,
  myStack = 0,
  onAction,
  disabled = false,
  showChat = false,
  onToggleChat = null,
  isMyTurn = false,
  unreadChatCount = 0,
}) {
  const [betAmount, setBetAmount] = useState(0);
  const [raiseAmount, setRaiseAmount] = useState(0);
  const [showBetControls, setShowBetControls] = useState(false);
  const [showRaiseControls, setShowRaiseControls] = useState(false);

  const callAmount = Math.max(0, currentBet - myBet);
  const canCheck = callAmount === 0 && legalActions.includes("check");
  const canCall = callAmount > 0 && callAmount <= myStack && legalActions.includes("call");
  const canBet = currentBet === 0 && legalActions.includes("bet");
  const canRaise = currentBet > 0 && legalActions.includes("raise");
  const canFold = legalActions.includes("fold");

  // Betting rules
  const minBet = Math.max(10, currentBet === 0 ? 10 : currentBet); // at least 10
  const maxBet = myStack;

  // Raise rules: must raise TO an amount (not by)
  const minRaise = Math.max(10, currentBet === 0 ? 10 : currentBet * 2);
  const maxRaise = myStack;

  // Snap any number to nearest 10
  const snap10 = (n) => Math.round(n / 10) * 10;

  // Initialize amounts
  useEffect(() => {
    if (canBet) {
      const base = Math.min(minBet, maxBet);
      setBetAmount(snap10(base));
    }
    if (canRaise) {
      const base = Math.min(minRaise, maxRaise);
      setRaiseAmount(snap10(base));
    }
  }, [canBet, canRaise, minBet, maxBet, minRaise, maxRaise]);

  // Hide controls when not legal
  useEffect(() => {
    if (!canBet) setShowBetControls(false);
    if (!canRaise) setShowRaiseControls(false);
  }, [canBet, canRaise]);

  const handleAction = (action, amount = 0) => {
    if (disabled || !onAction) return;
    onAction(action, amount);
    setShowBetControls(false);
    setShowRaiseControls(false);
  };

  const handleBetClick = () => {
    if (showBetControls) {
      handleAction("bet", betAmount);
    } else {
      setShowBetControls(true);
      setShowRaiseControls(false);
    }
  };

  const handleRaiseClick = () => {
    if (showRaiseControls) {
      handleAction("raise", raiseAmount - currentBet);
    } else {
      setShowRaiseControls(true);
      setShowBetControls(false);
    }
  };

  return (
    <div className="action-bar">

      {(showBetControls || showRaiseControls) && (
        <div className="action-controls">
          <div className="action-controls-content">
            <div className="action-controls-label">
              {showBetControls ? "Bet Amount" : "Raise To"}
            </div>

            <div className="action-controls-inputs">

              {/* SLIDER ONLY — NO TEXT INPUT FIELD */}
              <input
                type="range"
                min={showBetControls ? minBet : minRaise}
                max={showBetControls ? maxBet : maxRaise}
                step={10}
                value={showBetControls ? betAmount : raiseAmount}
                onChange={(e) => {
                  const raw = parseInt(e.target.value, 10);
                  let snapped = snap10(raw);

                  const min = showBetControls ? minBet : minRaise;
                  const max = showBetControls ? maxBet : maxRaise;

                  if (snapped < min) snapped = min;
                  if (snapped > max) snapped = max;

                  if (showBetControls) {
                    setBetAmount(snapped);
                  } else {
                    setRaiseAmount(snapped);
                  }
                }}
                className="action-slider"
              />

              <div className="action-amount-display">
                ${(showBetControls ? betAmount : raiseAmount).toLocaleString()}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Main Action Buttons */}
      <div className="action-buttons">

        {canCheck && (
          <button
            className="action-btn action-check-call"
            onClick={() => handleAction("check")}
            disabled={disabled}
          >
            Check
          </button>
        )}

        {canCall && (
          <button
            className="action-btn action-check-call"
            onClick={() => handleAction("call")}
            disabled={disabled}
          >
            Call ${callAmount.toLocaleString()}
          </button>
        )}

        {canBet && (
          <button
            className={`action-btn action-bet-raise ${showBetControls ? "active" : ""}`}
            onClick={handleBetClick}
            disabled={disabled}
          >
            {showBetControls ? `Bet $${betAmount.toLocaleString()}` : "Bet"}
          </button>
        )}

        {canRaise && (
          <button
            className={`action-btn action-bet-raise ${showRaiseControls ? "active" : ""}`}
            onClick={handleRaiseClick}
            disabled={disabled}
          >
            {showRaiseControls ? `Raise to $${raiseAmount.toLocaleString()}` : "Raise"}
          </button>
        )}

        {canFold && (
          <button
            className="action-btn action-fold"
            onClick={() => handleAction("fold")}
            disabled={disabled}
          >
            Fold
          </button>
        )}

        {onToggleChat && (
          <button
            className={`action-btn action-chat ${showChat ? "active" : ""}`}
            onClick={onToggleChat}
            disabled={false}
          >
            Chat
            {unreadChatCount > 0 && (
              <span className="chat-notification-badge">
                {unreadChatCount > 9 ? "9+" : unreadChatCount}
              </span>
            )}
          </button>
        )}
      </div>
    </div>
  );
}

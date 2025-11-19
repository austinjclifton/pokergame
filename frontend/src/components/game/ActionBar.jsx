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

  // Calculate min/max bet/raise amounts
  const minBet = currentBet === 0 ? 1 : currentBet;
  const maxBet = myStack;
  const minRaise = currentBet > 0 ? currentBet * 2 : minBet;
  const maxRaise = myStack;

  useEffect(() => {
    if (canBet) {
      setBetAmount(Math.min(minBet, maxBet));
    }
    if (canRaise) {
      setRaiseAmount(Math.min(minRaise, maxRaise));
    }
  }, [canBet, canRaise, minBet, maxBet, minRaise, maxRaise]);

  // Reset controls when actions change
  useEffect(() => {
    if (!canBet) setShowBetControls(false);
    if (!canRaise) setShowRaiseControls(false);
  }, [canBet, canRaise]);

  const handleAction = (action, amount = 0) => {
    if (disabled || !onAction) return;
    onAction(action, amount);
    // Reset controls after action
    setShowBetControls(false);
    setShowRaiseControls(false);
  };

  const handleBetClick = () => {
    if (showBetControls) {
      // If controls are showing, execute the bet
      handleAction("bet", betAmount);
    } else {
      // Show controls
      setShowBetControls(true);
      setShowRaiseControls(false);
    }
  };

  const handleRaiseClick = () => {
    if (showRaiseControls) {
      // If controls are showing, execute the raise
      handleAction("raise", raiseAmount - currentBet);
    } else {
      // Show controls
      setShowRaiseControls(true);
      setShowBetControls(false);
    }
  };

  // Always show the action bar, even if no legal actions (buttons will be disabled)
  // This ensures Chat button is always available

  return (
    <div className="action-bar">
      {/* Bet/Raise Controls - shown above buttons when active */}
      {(showBetControls || showRaiseControls) && (
        <div className="action-controls">
          <div className="action-controls-content">
            <div className="action-controls-label">
              {showBetControls ? "Bet Amount" : "Raise To"}
            </div>
            <div className="action-controls-inputs">
              <input
                type="number"
                min={showBetControls ? minBet : minRaise}
                max={showBetControls ? maxBet : maxRaise}
                value={showBetControls ? betAmount : raiseAmount}
                onChange={(e) => {
                  const val = parseInt(e.target.value, 10) || 0;
                  const min = showBetControls ? minBet : minRaise;
                  const max = showBetControls ? maxBet : maxRaise;
                  const clamped = Math.max(min, Math.min(max, val));
                  if (showBetControls) {
                    setBetAmount(clamped);
                  } else {
                    setRaiseAmount(clamped);
                  }
                }}
                className="action-number-input"
              />
              <input
                type="range"
                min={showBetControls ? minBet : minRaise}
                max={showBetControls ? maxBet : maxRaise}
                value={showBetControls ? betAmount : raiseAmount}
                onChange={(e) => {
                  const val = parseInt(e.target.value);
                  if (showBetControls) {
                    setBetAmount(val);
                  } else {
                    setRaiseAmount(val);
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

      {/* Main Action Buttons Row */}
      <div className="action-buttons">
        {/* Check or Call button */}
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

        {/* Bet or Raise button */}
        {canBet && (
          <button
            className={`action-btn action-bet-raise ${showBetControls ? 'active' : ''}`}
            onClick={handleBetClick}
            disabled={disabled}
          >
            {showBetControls ? `Bet $${betAmount.toLocaleString()}` : 'Bet'}
          </button>
        )}
        {canRaise && (
          <button
            className={`action-btn action-bet-raise ${showRaiseControls ? 'active' : ''}`}
            onClick={handleRaiseClick}
            disabled={disabled}
          >
            {showRaiseControls ? `Raise to $${raiseAmount.toLocaleString()}` : 'Raise'}
          </button>
        )}

        {/* Fold button */}
        {canFold && (
          <button
            className="action-btn action-fold"
            onClick={() => handleAction("fold")}
            disabled={disabled}
          >
            Fold
          </button>
        )}

        {/* Chat button - always enabled */}
        {onToggleChat && (
          <button
            className={`action-btn action-chat ${showChat ? 'active' : ''}`}
            onClick={onToggleChat}
            disabled={false}
          >
            Chat
            {unreadChatCount > 0 && (
              <span className="chat-notification-badge">
                {unreadChatCount > 9 ? '9+' : unreadChatCount}
              </span>
            )}
          </button>
        )}
      </div>
    </div>
  );
}


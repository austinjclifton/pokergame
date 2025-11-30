// frontend/src/components/game/ActionBar.jsx
import React, { useState, useEffect } from "react";
import "../../styles/game.css";

export default function ActionBar({
  legalActions = [],
  currentBet = 0,
  myBet = 0,
  myStack = 0,
  allPlayers = [],       // =======================================
                         // REQUIRED FOR EFFECTIVE STACK CALCULATION
                         // =======================================
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

  // ========================================================
  // EFFECTIVE STACK CALCULATION
  // Matching backend logic: max amount I can wager is
  // min(myStack, opponentStack + opponentBet).
  // This ensures all bets/raises/all-ins are always matchable.
  // ========================================================
  let effectiveStack = myStack;

  if (Array.isArray(allPlayers) && allPlayers.length >= 2) {
    // Find the opponent (1v1 situation)
    const opponent = allPlayers.find((p) => p && typeof p === "object" && p.stack !== undefined && p.bet !== undefined && p !== null && !(p.stack === myStack && p.bet === myBet));

    if (opponent) {
      effectiveStack = Math.min(myStack, opponent.stack + opponent.bet);
    }
  }

  // ========================================================
  // LEGAL ACTION FLAGS
  // ========================================================
  const canCheck =
    callAmount === 0 && legalActions.includes("check");

  const canCall =
    callAmount > 0 &&
    legalActions.includes("call");

  const canBet =
    currentBet === 0 &&
    legalActions.includes("bet");

  const canRaise =
    currentBet > 0 &&
    legalActions.includes("raise");

  const canFold = legalActions.includes("fold");

  // ========================================================
  // BETTING / RAISING RULES (SLIDER LIMITS)
  // Replaced all "myStack" max values with effectiveStack.
  // ========================================================
  const minBet = Math.max(10, currentBet === 0 ? 10 : currentBet);
  const maxBet = effectiveStack;

  const minRaise = Math.max(10, currentBet === 0 ? 10 : currentBet * 2);
  const maxRaise = effectiveStack;

  // Snap input to nearest 10
  const snap10 = (n) => Math.round(n / 10) * 10;

  // ========================================================
  // INITIALIZE DEFAULT VALUES
  // ========================================================
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

  // ========================================================
  // ACTION HANDLER
  // ========================================================
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

  // ========================================================
  // RENDER
  // ========================================================
  return (
    <div className="action-bar">

      {(showBetControls || showRaiseControls) && (
        <div className="action-controls">
          <div className="action-controls-content">
            <div className="action-controls-label">
              {showBetControls ? "Bet Amount" : "Raise To"}
            </div>

            <div className="action-controls-inputs">

              {/* SLIDER ONLY — NO MANUAL INPUT */}
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

      {/* MAIN ACTION BUTTONS */}
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

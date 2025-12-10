// frontend/src/components/game/ActionBar.jsx
// -----------------------------------------------------------------------------
// ActionBar Component (Production Grade)
// -----------------------------------------------------------------------------
// Responsibilities:
//   - Render action buttons the player may legally take.
//   - Manage bet / raise sliders.
//   - Always show a visible "Forfeit" button.
//   - Display chat button with unread message badge.
//   - Emit a standardized onAction(action, amount?) callback.
//   - Provide a small internal confirmation modal for forfeiting.
//
// Notes:
//   • No backend assumptions are made — "forfeit" is emitted like any action.
//   • Chat notifications require unreadChatCount from parent.
//   • Effective stack logic preserved exactly as previously used.
// -----------------------------------------------------------------------------

import React, { useState, useEffect } from "react";
import { createPortal } from "react-dom";
import "../../styles/game.css";

export default function ActionBar({
  legalActions = [],
  currentBet = 0,
  myBet = 0,
  myStack = 0,
  allPlayers = [],
  onAction,
  disabled = false,
  lastRaiseAmount = 0, // Minimum raise BY amount (from server or 0 for fallback)

  // Chat controls
  showChat = false,
  onToggleChat = null,
  unreadChatCount = 0,
}) {
  // ---------------------------------------------------------------------------
  // Local UI State
  // ---------------------------------------------------------------------------
  const [betAmount, setBetAmount] = useState(0);
  const [raiseAmount, setRaiseAmount] = useState(0);

  const [showBetControls, setShowBetControls] = useState(false);
  const [showRaiseControls, setShowRaiseControls] = useState(false);

  const [showForfeitConfirm, setShowForfeitConfirm] = useState(false);

  // ---------------------------------------------------------------------------
  // Computed values
  // ---------------------------------------------------------------------------

  // Amount required to call
  const callAmount = Math.max(0, currentBet - myBet);

  // ---------------------------------------------------------------------------
  // Effective stack (1v1): amount both players can meaningfully wager
  // ---------------------------------------------------------------------------
  let effectiveStack = myStack;

  if (Array.isArray(allPlayers) && allPlayers.length >= 2) {
    const opponent = allPlayers.find(
      (p) =>
        p &&
        typeof p === "object" &&
        p.stack !== undefined &&
        p.bet !== undefined &&
        !(p.stack === myStack && p.bet === myBet)
    );

    if (opponent) {
      effectiveStack = Math.min(myStack, opponent.stack + opponent.bet);
    }
  }

  // ---------------------------------------------------------------------------
  // Legal action flags (from server)
  // ---------------------------------------------------------------------------
  const canCheck = callAmount === 0 && legalActions.includes("check");
  const canCall = callAmount > 0 && legalActions.includes("call");
  const canBet = currentBet === 0 && legalActions.includes("bet");
  const canRaise = currentBet > 0 && legalActions.includes("raise");
  const canFold = legalActions.includes("fold");

  // Always allowed: match-level action
  const canForfeit = true;

  // ---------------------------------------------------------------------------
  // Slider / amount constraints
  // ---------------------------------------------------------------------------

  // BET (no live bet yet): use effectiveStack directly
  const minBet = Math.max(10, currentBet === 0 ? 10 : currentBet);
  const maxBet = effectiveStack;

  // Minimum RAISE BY = last raise amount from server, or 10 as a fallback
  const minRaiseBy = Math.max(10, lastRaiseAmount || 0);

  // Maximum RAISE BY:
  // We can only invest up to effectiveStack total this street.
  // For a raise, total chipsNeeded = callAmount + raiseBy.
  // ⇒ raiseBy ≤ effectiveStack - callAmount
  const maxRaiseBy = Math.max(0, effectiveStack - callAmount);

  const snap10 = (n) => Math.round(n / 10) * 10;

  // Initialize bet/raise values
  useEffect(() => {
    if (canBet) {
      const base = Math.min(minBet, maxBet);
      setBetAmount(snap10(base));
    }
    if (canRaise) {
      const base = Math.min(minRaiseBy, maxRaiseBy);
      setRaiseAmount(snap10(base));
    }
  }, [canBet, canRaise, minBet, maxBet, minRaiseBy, maxRaiseBy]);

  // Hide controls when not allowed
  useEffect(() => {
    if (!canBet) setShowBetControls(false);
    if (!canRaise) setShowRaiseControls(false);
  }, [canBet, canRaise]);

  // ---------------------------------------------------------------------------
  // Action Helpers
  // ---------------------------------------------------------------------------

  const emitAction = (action, amount = 0) => {
    if (disabled || !onAction) return;
    onAction(action, amount);

    // Reset UI controls
    setShowBetControls(false);
    setShowRaiseControls(false);
  };

  const onBetClick = () => {
    if (showBetControls) {
      emitAction("bet", betAmount);
    } else {
      setShowBetControls(true);
      setShowRaiseControls(false);
    }
  };

  const onRaiseClick = () => {
    if (showRaiseControls) {
      // raiseAmount is "raise BY" chips
      emitAction("raise", raiseAmount);
    } else {
      setShowRaiseControls(true);
      setShowBetControls(false);
    }
  };

  // ---------------------------------------------------------------------------
  // Forfeit Handling
  // ---------------------------------------------------------------------------
  const requestForfeit = () => setShowForfeitConfirm(true);
  const cancelForfeit = () => setShowForfeitConfirm(false);
  const confirmForfeit = () => {
    setShowForfeitConfirm(false);
    emitAction("forfeit");
  };

  // ---------------------------------------------------------------------------
  // Render
  // ---------------------------------------------------------------------------
  return (
    <div className="action-bar">
      {/* ------------------------------------------------------------
          MODAL: FORFEIT CONFIRMATION (rendered via portal outside action-bar)
         ------------------------------------------------------------ */}
      {showForfeitConfirm &&
        createPortal(
          <div className="forfeit-backdrop">
            <div className="forfeit-modal">
              <div className="forfeit-title">Forfeit Match?</div>
              <div className="forfeit-body">
                Your opponent will immediately win this match.
              </div>

              <div className="forfeit-actions">
                <button className="forfeit-btn cancel" onClick={cancelForfeit}>
                  Cancel
                </button>
                <button className="forfeit-btn confirm" onClick={confirmForfeit}>
                  Yes, Forfeit
                </button>
              </div>
            </div>
          </div>,
          document.body
        )}

      {/* ------------------------------------------------------------
           BET / RAISE SLIDER AREA
         ------------------------------------------------------------ */}
      {(showBetControls || showRaiseControls) && (
        <div className="action-controls">
          <div className="action-controls-content">

            <div className="action-controls-label">
              {showBetControls ? "Bet Amount" : "Raise by"}
            </div>

            <div className="action-controls-inputs">
              <input
                type="range"
                min={showBetControls ? minBet : minRaiseBy}
                max={showBetControls ? maxBet : maxRaiseBy}
                step={10}
                value={showBetControls ? betAmount : raiseAmount}
                onChange={(e) => {
                  const raw = parseInt(e.target.value, 10);
                  const min = showBetControls ? minBet : minRaiseBy;
                  const max = showBetControls ? maxBet : maxRaiseBy;

                  // 1️⃣ Clamp BEFORE snapping
                  const clamped = Math.max(min, Math.min(max, raw));

                  // 2️⃣ Snap AFTER clamping
                  const snapped = snap10(clamped);

                  if (showBetControls) setBetAmount(snapped);
                  else setRaiseAmount(snapped);
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

      {/* ------------------------------------------------------------
           MAIN ACTION BUTTONS
         ------------------------------------------------------------ */}
      <div className="action-buttons">

        {canCheck && (
          <button
            className="action-btn action-check-call"
            onClick={() => emitAction("check")}
            disabled={disabled}
          >
            Check
          </button>
        )}

        {canCall && (
          <button
            className="action-btn action-check-call"
            onClick={() => emitAction("call")}
            disabled={disabled}
          >
            Call ${callAmount.toLocaleString()}
          </button>
        )}

        {canBet && (
          <button
            className={`action-btn action-bet-raise ${
              showBetControls ? "active" : ""
            }`}
            onClick={onBetClick}
            disabled={disabled}
          >
            {showBetControls
              ? `Bet $${betAmount.toLocaleString()}`
              : "Bet"}
          </button>
        )}

        {canRaise && (
          <button
            className={`action-btn action-bet-raise ${
              showRaiseControls ? "active" : ""
            }`}
            onClick={onRaiseClick}
            disabled={disabled}
          >
            {showRaiseControls
              ? `Raise by $${raiseAmount.toLocaleString()}`
              : "Raise"}
          </button>
        )}

        {canFold && (
          <button
            className="action-btn action-fold"
            onClick={() => emitAction("fold")}
            disabled={disabled}
          >
            Fold
          </button>
        )}

        {/* ------------------------------------------------------------------
            FORFEIT BUTTON — ALWAYS VISIBLE
           ------------------------------------------------------------------ */}
        <button
          className="action-btn action-forfeit"
          onClick={requestForfeit}
          disabled={disabled}
        >
          Forfeit
        </button>

        {/* ------------------------------------------------------------------
            CHAT BUTTON (with unread message badge)
           ------------------------------------------------------------------ */}
        {onToggleChat && (
          <button
            className={`action-btn action-chat ${showChat ? "active" : ""}`}
            onClick={onToggleChat}
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

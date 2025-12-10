// frontend/src/components/game/MatchEndScreen.jsx
// -----------------------------------------------------------------------------
// MatchEndScreen
// -----------------------------------------------------------------------------
// Displays final match results for:
//   • showdown – board + both players’ cards
//   • fold     – board + winner’s cards only
//   • forfeit  – NO CARDS SHOWN AT ALL, clean informational screen
//
// This component defensively sanitizes card data for forfeit events to avoid
// stale data leaking from previous hands.
// -----------------------------------------------------------------------------

import React from "react";
import Card from "../cards/Card";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

export default function MatchEndScreen({ winner, loser, finalHand }) {
  if (!winner) return null; // Prevent rendering before server snapshot arrives

  // --- Extract raw data -------------------------------------------------------
  const rawBoard   = finalHand?.board   || [];
  const rawPlayers = finalHand?.players || {};
  const rawWinners = finalHand?.winners || [];
  const reason     = finalHand?.reason  || "showdown";

  // --- Match flags ------------------------------------------------------------
  const isForfeit = reason === "forfeit";
  const isFold    = reason === "fold";
  const isShowdown = reason === "showdown";

  // Losing cards appear *only* during showdown
  const showLosingCards = isShowdown;

  // Hand description suppressed for forfeit
  const handDescription = isForfeit ? null : finalHand?.handDescription;

  // --- Forfeit Safety Guard ---------------------------------------------------
  // Never trust backend card data on forfeit. Wipe everything that could show cards.
  const safeBoard   = isForfeit ? [] : rawBoard;
  const safePlayers = isForfeit ? {} : rawPlayers;
  const safeWinners = isForfeit ? [] : rawWinners;

  // --- Highlighting support ---------------------------------------------------
  const winningSeat =
    safeWinners.length > 0 ? Number(safeWinners[0].seat) : null;

  const bestHandsBySeat = new Map(
    safeWinners
      .filter((w) => Array.isArray(w.bestHand))
      .map((w) => [Number(w.seat), new Set(w.bestHand)])
  );

  // Helper: retrieve hole cards for a seat
  const getHoleCards = (seat) => safePlayers?.[seat]?.cards || [];

  // ============================================================================
  // FORFEIT MODE
  // ============================================================================
  if (isForfeit) {
    return (
      <div className="match-end-screen">
        <div className="match-end-card">
          <h1>Game Over</h1>

          {/* Winner */}
          <div className="match-end-section">
            <h2>Winner</h2>
            <p>{winner.username || `Player ${winner.seat}`}</p>
            <p>Final Stack: ${winner.stack?.toLocaleString()}</p>
            <div className="hand-result-hand-description">Wins by forfeit</div>
          </div>

          {/* Loser */}
          {loser && (
            <div className="match-end-section">
              <h2>Loser</h2>
              <p>{loser.username || `Player ${loser.seat}`}</p>
              <p>Final Stack: ${loser.stack?.toLocaleString()}</p>
              <div className="hand-result-hand-description">Forfeited</div>
            </div>
          )}

          {/* Return */}
          <button
            className="return-lobby-btn"
            onClick={() => (window.location.href = "/lobby")}
          >
            Return to Lobby
          </button>
        </div>
      </div>
    );
  }

  // ============================================================================
  // SHOWDOWN / FOLD MODE (May show cards depending on reason)
  // ============================================================================
  return (
    <div className="match-end-screen">
      <div className="match-end-card">
        <h1>Game Over</h1>

        {/* ===================== FINAL BOARD ===================== */}
        {safeBoard.length > 0 && (
          <div className="match-end-section">
            <h2>Final Board</h2>
            <div className="hand-result-cards">
              {safeBoard.map((cardStr, idx) => {
                const card = parseCard(cardStr);
                if (!card) return null;

                const highlight =
                  bestHandsBySeat.get(winningSeat)?.has(cardStr) || false;

                return (
                  <div
                    key={idx}
                    className={`hand-result-card-wrapper ${
                      highlight ? "best-hand-card" : ""
                    }`}
                  >
                    <div className="hand-result-card">
                      <Card suit={card.suit} rank={card.rank} revealed={true} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        )}

        {/* ===================== WINNER ===================== */}
        <div className="match-end-section">
          <h2>Winner</h2>
          <p>{winner.username || `Player ${winner.seat}`}</p>
          <p>Final Stack: ${winner.stack?.toLocaleString()}</p>

          {/* Winner Cards */}
          <div className="hand-result-cards">
            {getHoleCards(winner.seat).map((cardStr, idx) => {
              const card = parseCard(cardStr);
              if (!card) return null;

              const highlight =
                bestHandsBySeat.get(winner.seat)?.has(cardStr) || false;

              return (
                <div
                  key={idx}
                  className={`hand-result-card-wrapper ${
                    highlight ? "best-hand-card" : ""
                  }`}
                >
                  <div className="hand-result-card">
                    <Card suit={card.suit} rank={card.rank} revealed={true} />
                  </div>
                </div>
              );
            })}
          </div>

          {/* Winning Hand Description (only on showdown) */}
          {handDescription && (
            <div className="hand-result-hand-description">
              Winning Hand: {handDescription}
            </div>
          )}
        </div>

        {/* ===================== LOSER ===================== */}
        {loser && (
          <div className="match-end-section">
            <h2>Loser</h2>
            <p>{loser.username || `Player ${loser.seat}`}</p>
            <p>Final Stack: ${loser.stack?.toLocaleString()}</p>

            {/* Loser Cards: only shown during showdown */}
            {showLosingCards && (
              <div className="hand-result-cards">
                {getHoleCards(loser.seat).map((cardStr, idx) => {
                  const card = parseCard(cardStr);
                  if (!card) return null;

                  const highlight =
                    bestHandsBySeat.get(loser.seat)?.has(cardStr) || false;

                  return (
                    <div
                      key={idx}
                      className={`hand-result-card-wrapper ${
                        highlight ? "best-hand-card" : ""
                      }`}
                    >
                      <div className="hand-result-card">
                        <Card suit={card.suit} rank={card.rank} revealed={true} />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {/* Fold message */}
            {isFold && (
              <div className="hand-result-hand-description">Folded</div>
            )}
          </div>
        )}

        {/* RETURN */}
        <button
          className="return-lobby-btn"
          onClick={() => (window.location.href = "/lobby")}
        >
          Return to Lobby
        </button>
      </div>
    </div>
  );
}

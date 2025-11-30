// frontend/src/components/game/MatchEndScreen.jsx
import React from "react";
import Card from "../cards/Card";
import { parseCard } from "../../utils/cardParser";
import "../../styles/game.css";

/**
 * MatchEndScreen
 * Final match summary with card display.
 *
 * Props:
 *  - winner: { username, seat, stack }
 *  - loser: { username, seat, stack }
 *  - finalHand: full hand summary from useGameSocket.matchResult.finalHand
 */
export default function MatchEndScreen({ winner, loser, finalHand }) {
  if (!winner) return null;

  const board = finalHand?.board || [];
  const players = finalHand?.players || {};
  const winners = finalHand?.winners || [];
  const reason = finalHand?.reason || "showdown";

  // winner seat
  const winningSeat = winners[0] ? Number(winners[0].seat) : null;

  // best hand card set (used for highlighting)
  const bestHandsBySeat = new Map(
    winners
      .filter((w) => Array.isArray(w.bestHand))
      .map((w) => [Number(w.seat), new Set(w.bestHand)])
  );

  // Helper: get player hole cards based on seat
  const getHole = (seat) => players?.[seat]?.cards || [];

  // If fold, hide losing player's cards
  const showLosingCards = reason !== "fold";

  return (
    <div className="match-end-screen">
      <div className="match-end-card">
        <h1>Game Over</h1>

        {/* ===================== FINAL BOARD ===================== */}
        {board.length === 5 && (
          <div className="match-end-section">
            <h2>Final Board</h2>
            <div className="hand-result-cards">
              {board.map((cardStr, idx) => {
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

          {/* Winner's hole cards */}
          <div className="hand-result-cards">
            {getHole(winner.seat)?.map((cardStr, idx) => {
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

          {/* Winner's hand description */}
          {finalHand?.handDescription && (
            <div className="hand-result-hand-description">
              Winning Hand: {finalHand.handDescription}
            </div>
          )}
        </div>

        {/* ===================== LOSER ===================== */}
        {loser && (
          <div className="match-end-section">
            <h2>Loser</h2>
            <p>{loser.username || `Player ${loser.seat}`}</p>
            <p>Final Stack: ${loser.stack?.toLocaleString()}</p>

            {/* Loser's hole cards (only show on showdown) */}
            {showLosingCards && (
              <div className="hand-result-cards">
                {getHole(loser.seat)?.map((cardStr, idx) => {
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
                        <Card
                          suit={card.suit}
                          rank={card.rank}
                          revealed={true}
                        />
                      </div>
                    </div>
                  );
                })}
              </div>
            )}

            {reason === "fold" && (
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

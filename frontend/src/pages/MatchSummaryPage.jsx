// frontend/src/pages/MatchSummaryPage.jsx
// -----------------------------------------------------------------------------
// FINAL MATCH SUMMARY PAGE
// - Winner info
// - Loser info
// - Winner's 2 hole cards
// - Final 5-card board
// - Hand description
// - NO pot, NO best 5-card section
// Reads strictly from localStorage (matchSummary_<tableId>).
// -----------------------------------------------------------------------------

import React, { useEffect, useState } from "react";
import { useParams, useNavigate } from "react-router-dom";
import Card from "../components/cards/Card";
import { parseCard } from "../utils/cardParser";
import "../styles/game/matchEnd.css";

export default function MatchSummaryPage() {
  const { tableId } = useParams();
  const navigate = useNavigate();
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(true);

  // ---------------------------------------------------------------------------
  // Load results from localStorage
  // ---------------------------------------------------------------------------
  useEffect(() => {
    if (!tableId) {
      navigate("/lobby", { replace: true });
      return;
    }

    try {
      const raw = localStorage.getItem(`matchSummary_${tableId}`);
      if (!raw) {
        navigate("/lobby", { replace: true });
        return;
      }

      setSummary(JSON.parse(raw));
    } catch (err) {
      console.error("Failed to parse match summary:", err);
      navigate("/lobby", { replace: true });
    } finally {
      setLoading(false);
    }
  }, [tableId, navigate]);

  const returnToLobby = () => navigate("/lobby", { replace: true });

  if (loading) {
    return (
      <div className="match-summary-page">
        <div className="match-summary-container">
          <p>Loading match summary...</p>
        </div>
      </div>
    );
  }

  if (!summary) return null;

  // Extract final data
  const { winner, loser, finalHand } = summary;
  const winningSeat = winner?.seat;
  const winningHand = finalHand?.winners?.[0] || null;

  const holeCards = finalHand?.players?.[winningSeat]?.cards || [];
  const boardCards = finalHand?.board || [];

  return (
    <div className="match-summary-page">
      <div className="match-summary-container">

        <h1 className="match-summary-title">Game Over</h1>

        {/* Winner */}
        {winner && (
          <div className="match-summary-block winner-block">
            <h2>Winner</h2>
            <p className="player-name">{winner.username || `Player ${winner.seat}`}</p>
            <p className="player-stack">Final Stack: ${winner.stack?.toLocaleString() || 0}</p>
          </div>
        )}

        {/* Final Hand */}
        {finalHand && winningHand && (
          <div className="match-summary-block final-hand-block">
            <h2>Final Hand</h2>

            {/* Hand description */}
            {winningHand.handDescription && (
              <p className="hand-desc">{winningHand.handDescription}</p>
            )}

            {/* Winner hole cards */}
            {holeCards.length === 2 && (
              <div className="card-section">
                <h3>Winner's Hole Cards</h3>
                <div className="hole-cards">
                  {holeCards.map((raw, i) => {
                    const card = parseCard(raw);
                    if (!card) return null;
                    return <Card key={i} suit={card.suit} rank={card.rank} revealed={true} />;
                  })}
                </div>
              </div>
            )}

            {/* Final Board */}
            {boardCards.length > 0 && (
              <div className="card-section">
                <h3>Final Board</h3>
                <div className="board-cards">
                  {boardCards.map((raw, i) => {
                    const card = parseCard(raw);
                    if (!card) return null;
                    return <Card key={i} suit={card.suit} rank={card.rank} revealed={true} />;
                  })}
                </div>
              </div>
            )}
          </div>
        )}

        {/* Loser */}
        {loser && (
          <div className="match-summary-block loser-block">
            <h2>Loser</h2>
            <p className="player-name">{loser.username || `Player ${loser.seat}`}</p>
            <p className="player-stack">Final Stack: ${loser.stack?.toLocaleString() || 0}</p>
          </div>
        )}

        <button className="return-lobby-btn" onClick={returnToLobby}>
          Return to Lobby
        </button>
      </div>
    </div>
  );
}

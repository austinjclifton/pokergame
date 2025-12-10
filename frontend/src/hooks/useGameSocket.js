// frontend/src/hooks/useGameSocket.js
// -----------------------------------------------------------------------------
// useGameSocket – WebSocket manager for poker game
//
// Responsibilities:
//   • Maintain authoritative game state (public + private).
//   • Handle reconnection, ping/pong, and sync/diff messages.
//   • Manage chat history + unread counts.
//   • Track per-hand summary (handSummary) for overlays.
//   • Build a robust matchResult for final match summary screens.
//   • Enforce safe behavior for forfeits (no cards leaked).
//
// Design notes:
//   • All live-game updates come from STATE_SYNC / STATE_DIFF / STATE_PRIVATE.
//   • hand_end stores a rich per-hand "summary" into lastHandSummaryRef.
//   • match_end freezes state and builds a finalHand snapshot that is safe
//     and accurate for showdown, folds, all-ins, and forfeits.
//   • matchEndedRef is used to stop further live updates after match_end.
// -----------------------------------------------------------------------------

import { useEffect, useRef, useState, useCallback } from "react";
import API from "../config/api";

// -----------------------------------------------------------------------------
// Initial State Factory
// -----------------------------------------------------------------------------
function createInitialGameState() {
  return {
    tableId: null,
    gameId: null,
    gameVersion: 0,

    // Public table state
    community: [],
    seats: [],
    pots: [],
    pot: 0,
    currentBet: 0,
    actionSeat: null,
    currentTurn: null,
    phase: "WAITING",

    // Private state (local player only)
    mySeat: null,
    holeCards: [],
    legalActions: [],

    // Connection state
    connected: false,
    reconnecting: false,

    // Chat
    chatMessages: [],
    unreadChatCount: 0,
    showChat: false,

    // Hand result summary for overlay (per-hand)
    handSummary: null,

    // Full match-level state
    matchEnded: false,
    matchResult: null, // { winner, loser, finalHand }
  };
}

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------

/**
 * Build seat view objects from a server "state" object that has `players`.
 * Used for live play (STATE_SYNC / STATE_DIFF / hand_start).
 */
function buildSeatsFromState(state, prevSeats = []) {
  const players = state.players || {};
  const prevMap = new Map(prevSeats.map((s) => [s.seat_no, s]));
  const seats = [];

  for (const key of Object.keys(players)) {
    const seatNo = parseInt(key, 10);
    const p = players[key];
    const prev = prevMap.get(seatNo);

    let status = "active";
    if (p.folded) status = "folded";
    else if (p.allIn) status = "all_in";

    // Preserve disconnected/away flag if server doesn't override
    if (prev?.status === "disconnected" || prev?.status === "away") {
      if (status === "active") status = prev.status;
    }

    seats.push({
      seat_no: seatNo,
      user_id: p.user_id ?? prev?.user_id ?? null,
      name: p.username || prev?.name || `Seat ${seatNo}`,
      username: p.username || prev?.username || null,
      stack: p.stack ?? 0,
      bet: p.bet ?? 0,
      status,
      // For live state, cards are not known here; handled separately via hand summaries
      cards: [],
      handRank: p.handRank,
      handDescription: p.handDescription,
    });
  }

  seats.sort((a, b) => a.seat_no - b.seat_no);
  return seats;
}

/**
 * Normalize a finalHand.players object:
 *   • Keys may be seat numbers or strings – normalize to string keys "seat".
 *   • Ensure each player entry has cards array, folded, stack, bet, etc.
 */
function normalizeFinalHandPlayers(rawPlayers, fallbackSeats = []) {
  const players = {};
  const prevMap = new Map(fallbackSeats.map((s) => [s.seat_no, s]));

  if (!rawPlayers || typeof rawPlayers !== "object") return players;

  for (const key of Object.keys(rawPlayers)) {
    const raw = rawPlayers[key] || {};
    const seatNo =
      raw.seat != null
        ? Number(raw.seat)
        : Number.isNaN(Number(key))
        ? null
        : Number(key);

    if (seatNo == null) continue;

    const prev = prevMap.get(seatNo);

    players[seatNo] = {
      seat: seatNo,
      user_id: raw.user_id ?? prev?.user_id ?? null,
      username: raw.username || prev?.username || prev?.name || null,
      cards: Array.isArray(raw.cards) ? raw.cards : [],
      folded: !!raw.folded,
      stack: raw.stack ?? prev?.stack ?? 0,
      bet: raw.bet ?? prev?.bet ?? 0,
      contribution: raw.contribution ?? raw.bet ?? prev?.bet ?? 0,
      handRank: raw.handRank ?? prev?.handRank,
      handDescription: raw.handDescription ?? prev?.handDescription ?? null,
    };
  }

  return players;
}

/**
 * Build a "seats" array from finalHand.players for the end-of-match view.
 * This is used only after match_end to render the last known stacks + cards.
 */
function buildSeatsFromFinalHand(finalHand, prevSeats = []) {
  const players = finalHand?.players || {};
  const prevMap = new Map(prevSeats.map((s) => [s.seat_no, s]));
  const seats = [];

  for (const seatKey of Object.keys(players)) {
    const p = players[seatKey];
    if (!p) continue;

    const seatNo = Number(p.seat ?? seatKey);
    const prev = prevMap.get(seatNo);

    const status = p.folded ? "folded" : "active";

    seats.push({
      seat_no: seatNo,
      user_id: p.user_id ?? prev?.user_id ?? null,
      name: p.username || prev?.name || `Seat ${seatNo}`,
      username: p.username || prev?.username || null,
      stack: p.stack ?? prev?.stack ?? 0,
      bet: p.bet ?? prev?.bet ?? 0,
      status,
      cards: Array.isArray(p.cards) ? p.cards : [],
      handRank: p.handRank ?? prev?.handRank,
      handDescription: p.handDescription ?? prev?.handDescription,
    });
  }

  // Retain any seats that didn't appear in finalHand but were in prevSeats
  for (const prev of prevSeats) {
    if (!seats.some((s) => s.seat_no === prev.seat_no)) {
      seats.push(prev);
    }
  }

  seats.sort((a, b) => a.seat_no - b.seat_no);
  return seats;
}

/**
 * Build a robust finalHand snapshot for NON-FORFEIT match_end.
 * Priority:
 *   1. lastHandSummaryRef (server hand_end summary: board, players, winners, reason)
 *   2. msg.finalHand or msg.board or stateRef.current.community as fallback board
 */
function buildNonForfeitFinalHand({
  msg,
  lastHandSummary,
  currentState,
}) {
  const matchReason = msg.reason || null;

  let finalHand = null;

  // Prefer server-provided hand summary (from hand_end)
  if (lastHandSummary && typeof lastHandSummary === "object") {
    finalHand = { ...lastHandSummary };
  } else {
    finalHand = {};
  }

  // --- Board ------------------------------------------------------------------
  // Try to get a full 5-card board from:
  //   • summary.board
  //   • msg.finalHand.board
  //   • msg.board
  //   • currentState.community
  let board =
    (Array.isArray(finalHand.board) && finalHand.board.length > 0
      ? finalHand.board
      : null) ||
    (msg.finalHand &&
      Array.isArray(msg.finalHand.board) &&
      msg.finalHand.board.length > 0 &&
      msg.finalHand.board) ||
    (Array.isArray(msg.board) && msg.board.length > 0 && msg.board) ||
    (Array.isArray(currentState.community) && currentState.community.length > 0
      ? currentState.community
      : []);

  finalHand.board = board;

  // --- Players ----------------------------------------------------------------
  // Normalize player entries (cards, folded, stack, etc.)
  const normalizedPlayers = normalizeFinalHandPlayers(
    finalHand.players,
    currentState.seats || []
  );
  finalHand.players = normalizedPlayers;

  // --- Winners ----------------------------------------------------------------
  let winners = Array.isArray(finalHand.winners) ? finalHand.winners.slice() : [];

  // If no winners present in summary, build a minimal winner from msg.winner
  if (winners.length === 0 && msg.winner?.seat != null) {
    winners.push({
      seat: msg.winner.seat,
      amount: currentState.pot || 0,
      bestHand: [],
    });
  }

  finalHand.winners = winners;

  // --- Reason + HandDescription ----------------------------------------------
  finalHand.reason = matchReason || finalHand.reason || "showdown";
  if (!("handDescription" in finalHand)) {
    finalHand.handDescription = null;
  }

  return finalHand;
}

// -----------------------------------------------------------------------------
// Hook
// -----------------------------------------------------------------------------
export default function useGameSocket(tableId, onError, onMatchEnd) {
  const socketRef = useRef(null);
  const stateRef = useRef({ isMounted: false, isConnecting: false });
  const lastConnectRef = useRef(0);
  const reconnectAttemptsRef = useRef(0);
  const onErrorRef = useRef(onError);
  const onMatchEndRef = useRef(onMatchEnd);

  // Prevent stale reads of matchEnded inside async callbacks
  const matchEndedRef = useRef(false);

  // Store last hand summary for match end
  const lastHandSummaryRef = useRef(null);

  const maxReconnectAttempts = 10;

  const [gameState, setGameState] = useState(createInitialGameState);

  // Keep stateRef always synced to latest gameState
  useEffect(() => {
    stateRef.current = {
      ...stateRef.current,
      ...gameState,
    };
  }, [gameState]);

  // Keep the latest onError callback
  useEffect(() => {
    onErrorRef.current = onError;
  }, [onError]);

  // Keep the latest onMatchEnd callback
  useEffect(() => {
    onMatchEndRef.current = onMatchEnd;
  }, [onMatchEnd]);

  // Keep matchEndedRef synced
  useEffect(() => {
    matchEndedRef.current = gameState.matchEnded;
  }, [gameState.matchEnded]);

  // ---------------------------------------------------------------------------
  // Public API: actions, chat, next-hand, sync, disconnect
  // ---------------------------------------------------------------------------

  const sendAction = useCallback(
    (action, amount = 0) => {
      // Block actions if match has ended
      if (gameState.matchEnded || matchEndedRef.current) {
        onErrorRef.current?.("Match has ended");
        return;
      }

      const ws = socketRef.current;
      if (!ws || ws.readyState !== WebSocket.OPEN) {
        onErrorRef.current?.("Not connected to game server");
        return;
      }

      try {
        ws.send(
          JSON.stringify({
            type: "action",
            action,
            amount,
            game_version: gameState.gameVersion,
          })
        );
      } catch (err) {
        onErrorRef.current?.(`Failed to send action: ${err.message}`);
      }
    },
    [gameState.gameVersion, gameState.matchEnded]
  );

  const sendChat = useCallback((message) => {
    const text = message?.trim();
    if (!text) return;

    const ws = socketRef.current;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      onErrorRef.current?.("Not connected to game server");
      return;
    }

    try {
      ws.send(JSON.stringify({ type: "chat", msg: text }));
    } catch (err) {
      onErrorRef.current?.(`Failed to send chat message: ${err.message}`);
    }
  }, []);

  const sendNextHand = useCallback(() => {
    // Block next_hand if match has ended
    if (gameState.matchEnded || matchEndedRef.current) {
      return;
    }

    const ws = socketRef.current;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      onErrorRef.current?.("Not connected to game server");
      return;
    }

    try {
      ws.send(JSON.stringify({ type: "next_hand" }));
    } catch (err) {
      onErrorRef.current?.(`Failed to send next_hand request: ${err.message}`);
    }
  }, [gameState.matchEnded]);

  const requestSync = useCallback(() => {
    const ws = socketRef.current;

    if (!ws || ws.readyState !== WebSocket.OPEN) {
      if (tableId) {
        stateRef.current.isMounted = true;
        lastConnectRef.current = 0;
        reconnectAttemptsRef.current = 0;
      }
      return;
    }

    try {
      ws.send(JSON.stringify({ type: "chat_history" }));
    } catch (err) {
      onErrorRef.current?.(`Failed to request sync: ${err.message}`);
    }
  }, [tableId]);

  const disconnect = useCallback(() => {
    const ws = socketRef.current;
    if (ws) {
      try {
        ws.close(1000, "User disconnect");
      } catch {
        // ignore
      }
    }
    socketRef.current = null;

    setGameState((prev) => ({
      ...prev,
      connected: false,
      reconnecting: false,
    }));
  }, []);

  const toggleChat = useCallback(() => {
    setGameState((prev) => ({
      ...prev,
      showChat: !prev.showChat,
      unreadChatCount: 0,
    }));
  }, []);

  // ---------------------------------------------------------------------------
  // Chat helpers
  // ---------------------------------------------------------------------------

  const appendChatMessage = useCallback((msg) => {
    const createdAt = msg.created_at || new Date().toISOString();
    const time =
      msg.time ||
      new Date(createdAt).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      });

    const message = {
      from: msg.from,
      msg: msg.msg,
      time,
      created_at: createdAt,
    };

    setGameState((prev) => ({
      ...prev,
      chatMessages: [...prev.chatMessages, message],
      unreadChatCount: prev.showChat
        ? prev.unreadChatCount
        : prev.unreadChatCount + 1,
    }));
  }, []);

  const replaceChatHistory = useCallback((msg) => {
    const messages = (msg.messages || []).map((m) => {
      const createdAt = m.created_at || new Date().toISOString();
      const time =
        m.time ||
        new Date(createdAt).toLocaleTimeString([], {
          hour: "2-digit",
          minute: "2-digit",
        });

      return { from: m.from, msg: m.msg, time, created_at: createdAt };
    });

    setGameState((prev) => ({
      ...prev,
      chatMessages: messages,
    }));
  }, []);

  // ---------------------------------------------------------------------------
  // Main WebSocket Effect
  // ---------------------------------------------------------------------------

  useEffect(() => {
    if (!tableId) return;

    let ws;
    let pingInterval;
    let reconnectTimeout;

    stateRef.current.isMounted = true;

    // -------------------------------------------------------------------------
    // STATE_SYNC – full snapshot
    // -------------------------------------------------------------------------
    const handleStateSync = (msg) => {
      if (matchEndedRef.current) return;

      const s = msg.state || {};
      const version = msg.version ?? 0;

      setGameState((prev) => ({
        ...prev,
        tableId,
        gameId: msg.game_id ?? null,
        gameVersion: version,
        community: s.board || [],
        seats: buildSeatsFromState(s),
        pots: s.pots || [{ amount: s.pot || 0 }],
        pot: s.pot || 0,
        currentBet: s.currentBet || 0,
        actionSeat: s.actionSeat ?? null,
        currentTurn: s.actionSeat ?? null,
        phase: (s.phase || "preflop").toString().toUpperCase(),
        connected: true,
        reconnecting: false,
      }));
    };

    // -------------------------------------------------------------------------
    // STATE_DIFF – incremental updates
    // -------------------------------------------------------------------------
    const handleStateDiff = (msg) => {
      if (matchEndedRef.current) return;

      const s = msg.state || {};
      const version = msg.version ?? null;

      setGameState((prev) => ({
        ...prev,
        gameVersion: version ?? prev.gameVersion,
        community: s.board !== undefined ? s.board : prev.community,
        seats: buildSeatsFromState(s, prev.seats),
        pots: s.pots || [{ amount: s.pot ?? prev.pot }],
        pot: s.pot !== undefined ? s.pot : prev.pot,
        currentBet: s.currentBet ?? prev.currentBet,
        actionSeat: s.actionSeat ?? prev.actionSeat,
        currentTurn: s.actionSeat ?? prev.currentTurn,
        phase: s.phase ? s.phase.toString().toUpperCase() : prev.phase,
      }));
    };

    // -------------------------------------------------------------------------
    // STATE_PRIVATE – hole cards + legal actions (per-player)
    // -------------------------------------------------------------------------
    const handleStatePrivate = (msg) => {
      if (matchEndedRef.current) return;

      const p = msg.state || {};

      setGameState((prev) => ({
        ...prev,
        mySeat: p.mySeat ?? msg.seat ?? prev.mySeat,
        holeCards: p.myCards || p.cards || prev.holeCards,
        legalActions: p.legalActions ?? prev.legalActions,
      }));
    };

    // -------------------------------------------------------------------------
    // hand_end – per-hand result summary (used for overlays + match_end)
    // -------------------------------------------------------------------------
    const handleHandEnd = (msg) => {
      const summary = msg.summary || msg.payload || msg;

      // Store hand summary for potential match end
      lastHandSummaryRef.current = summary;

      setGameState((prev) => ({
        ...prev,
        handSummary: summary,
      }));

      // If match already ended, DO NOT auto-advance or request next hand.
      if (matchEndedRef.current) return;

      // Auto-advance after delay
      setTimeout(() => {
        if (!matchEndedRef.current) {
          const wsCurrent = socketRef.current;
          if (wsCurrent && wsCurrent.readyState === WebSocket.OPEN) {
            wsCurrent.send(JSON.stringify({ type: "next_hand" }));
          }
        }
      }, 5000);
    };

    // -------------------------------------------------------------------------
    // hand_start – new hand broadcast
    // -------------------------------------------------------------------------
    const handleHandStart = (msg) => {
      if (matchEndedRef.current) return;

      // Reset last summary for the new hand
      lastHandSummaryRef.current = null;

      const s = msg.state || {};
      const version = msg.version ?? null;

      setGameState((prev) => ({
        ...prev,
        gameVersion: version ?? prev.gameVersion,
        community: s.board || [],
        seats: buildSeatsFromState(s, prev.seats),
        pots: s.pots || [{ amount: s.pot || 0 }],
        pot: s.pot || 0,
        currentBet: s.currentBet || 0,
        actionSeat: s.actionSeat ?? null,
        currentTurn: s.actionSeat ?? null,
        phase: (s.phase || "preflop").toString().toUpperCase(),
        legalActions: [],
      }));
    };

    // -------------------------------------------------------------------------
    // match_end – final winner / loser / final hand snapshot
    // -------------------------------------------------------------------------
    const handleMatchEnd = (msg) => {
      // Freeze all further activity
      matchEndedRef.current = true;
    
      const matchReason = msg.reason || null;
      const currentState = stateRef.current;
      const lastHandSummary = lastHandSummaryRef.current;
    
      let finalHand;
    
      // ============================================================================
      // 1) FORFEIT — always zero cards, never reuse stale data
      // ============================================================================
      if (matchReason === "forfeit") {
        finalHand = {
          board: [],
          players: {},
          winners: [
            {
              seat: msg.winner?.seat ?? null,
              bestHand: [],
              amount: 0,
            },
          ],
          reason: "forfeit",
          handDescription: null,
        };
      } else {
        // ==========================================================================
        // 2) NON-FORFEIT — prefer lastHandSummary; fallback to current state
        // ==========================================================================
        // Check both lastHandSummaryRef and currentState.handSummary
        // (handSummary might be in state even if ref wasn't updated)
        const availableSummary = lastHandSummary || currentState.handSummary;
        
        if (availableSummary && typeof availableSummary === "object") {
          // Clone the server hand_end summary (authoritative when present)
          finalHand = { ...availableSummary };
        } else {
          // FIRST HAND or cases where hand_end never fired:
          // build a minimal finalHand from current state + match_end payload.
          // Try to get player cards from msg.players (backend sends this in match_end)
          // or fallback to currentState
          let playersFromMsg = {};
          
          if (msg.players && typeof msg.players === "object") {
            // Backend sent player data in match_end message
            playersFromMsg = msg.players;
          } else {
            // Fallback: build from currentState (may not have cards)
            const currentSeats = currentState.seats || [];
            currentSeats.forEach(seat => {
              if (seat) {
                playersFromMsg[seat.seat_no] = {
                  seat: seat.seat_no,
                  user_id: seat.user_id,
                  username: seat.username || seat.name || null,
                  cards: seat.cards || [],
                  folded: seat.status === 'folded',
                  stack: seat.stack,
                  bet: seat.bet || 0,
                  contribution: seat.contribution || seat.bet || 0,
                };
              }
            });
          }
          
          finalHand = {
            board: [],
            players: playersFromMsg,
            winners: [],
            reason: matchReason || "showdown",
            handDescription: null,
          };
        }
    
        // --- Normalize players: ensure consistent shape + cards array -------------
        // Uses current seats as fallback for stack/bet/etc.
        finalHand.players = normalizeFinalHandPlayers(
          finalHand.players,
          currentState.seats || []
        );
    
        // --- Ensure winners exist -------------------------------------------------
        if (!Array.isArray(finalHand.winners) || finalHand.winners.length === 0) {
          if (msg.winner?.seat != null) {
            finalHand.winners = [
              {
                seat: msg.winner.seat,
                amount: currentState.pot || 0,
                bestHand: [],
              },
            ];
          } else {
            finalHand.winners = [];
          }
        }
    
        // --- Resolve FINAL BOARD (key fix for first-hand all-ins) -----------------
        let boardCandidate = [];
    
        // 1. If summary already has a complete board, trust it.
        if (Array.isArray(finalHand.board) && finalHand.board.length === 5) {
          boardCandidate = finalHand.board;
        }
        // 2. Try msg.board (if backend ever sends it on match_end).
        else if (Array.isArray(msg.board) && msg.board.length > 0) {
          boardCandidate = msg.board;
        }
        // 3. Try msg.finalHand.board (some backends use this).
        else if (
          msg.finalHand &&
          Array.isArray(msg.finalHand.board) &&
          msg.finalHand.board.length > 0
        ) {
          boardCandidate = msg.finalHand.board;
        }
        // 4. Check currentState.community - if it has 5 cards, use it (full board)
        //    This handles cases where STATE_SYNC updated the board before match_end
        else if (
          Array.isArray(currentState.community) &&
          currentState.community.length === 5
        ) {
          boardCandidate = currentState.community;
        }
        // 5. If currentState.community is partial but hand ended, backend should have
        //    dealt all 5 cards. For all-in scenarios, check if we can infer full board.
        //    For now, use whatever we have (may be partial for preflop all-ins)
        else if (
          Array.isArray(currentState.community) &&
          currentState.community.length > 0
        ) {
          boardCandidate = currentState.community;
        }
        // 6. Absolute fallback: leave empty (only valid for preflop all-in
        //    when backend truly never sent any board cards).
        else {
          boardCandidate = [];
        }
    
        finalHand.board = boardCandidate;
    
        // --- Reason + hand description -------------------------------------------
        finalHand.reason = matchReason || finalHand.reason || "showdown";
        if (!("handDescription" in finalHand)) {
          finalHand.handDescription = null;
        }
      }
    
      // ============================================================================
      // Sanity checks
      // ============================================================================
      if (!Array.isArray(finalHand.board)) {
        console.warn("[useGameSocket] finalHand.board missing or invalid");
        finalHand.board = [];
      }
      if (!finalHand.players || typeof finalHand.players !== "object") {
        console.warn("[useGameSocket] finalHand.players missing or invalid");
        finalHand.players = {};
      }
      if (!Array.isArray(finalHand.winners)) {
        console.warn("[useGameSocket] finalHand.winners missing or invalid");
        finalHand.winners = [];
      }
    
      // ============================================================================
      // Build seats for final view (used by MatchEndScreen)
      // ============================================================================
      const finalSeats = buildSeatsFromFinalHand(
        finalHand,
        currentState.seats || []
      );
    
      // ============================================================================
      // Build match summary for separate summary page
      // ============================================================================
      const matchSummary = {
        tableId,
        matchEnded: true,
        winner: msg.winner,
        loser: msg.loser,
        finalHand, // Full hand summary (board + players + winners)
        endedAt: Date.now(),
      };
    
      // ============================================================================
      // Commit final game state
      // ============================================================================
      setGameState((prev) => ({
        ...prev,
        matchEnded: true,
        matchResult: {
          winner: msg.winner,
          loser: msg.loser,
          finalHand,
        },
    
        // Stop all in-game actions
        actionSeat: null,
        currentTurn: null,
        legalActions: [],
    
        // Use authoritative final board + seats
        community: finalHand.board || prev.community,
        seats: finalSeats,
    
        // Preserve last per-hand summary + private cards for overlay components
        handSummary: prev.handSummary,
        holeCards: prev.holeCards,
      }));
    
      // Persist match summary (for redirect-based summary page)
      try {
        localStorage.setItem(
          `matchSummary_${tableId}`,
          JSON.stringify(matchSummary)
        );
      } catch (e) {
        console.error("Failed to save match summary:", e);
      }
    
      // Navigate to summary page / parent handler
      if (onMatchEndRef.current) {
        onMatchEndRef.current(tableId);
      } else {
        window.location.href = `/match/${tableId}/summary`;
      }
    };    

    // -------------------------------------------------------------------------
    // Player connection status events
    // -------------------------------------------------------------------------
    const handlePlayerConnected = (msg) => {
      const seatNo = msg.seat_no;
      if (seatNo == null) return;

      setGameState((prev) => ({
        ...prev,
        seats: prev.seats.map((s) =>
          s.seat_no === seatNo
            ? { ...s, status: "active", disconnected: false }
            : s
        ),
      }));
    };

    const handlePlayerAway = (msg) => {
      const seatNo = msg.seat_no;
      if (seatNo == null) return;

      setGameState((prev) => ({
        ...prev,
        seats: prev.seats.map((s) =>
          s.seat_no === seatNo
            ? { ...s, status: "away", disconnected: true }
            : s
        ),
      }));
    };

    // -------------------------------------------------------------------------
    // Connection init + reconnection logic
    // -------------------------------------------------------------------------
    const init = async () => {
      const now = Date.now();
      if (stateRef.current.isConnecting || !stateRef.current.isMounted) return;
      if (now - lastConnectRef.current < 1000) return;

      if (reconnectAttemptsRef.current >= maxReconnectAttempts) {
        onErrorRef.current?.("Max reconnection attempts reached");
        return;
      }

      // Close existing WS if needed
      if (socketRef.current) {
        try {
          const existing = socketRef.current;
          if (
            existing.readyState === WebSocket.OPEN ||
            existing.readyState === WebSocket.CONNECTING
          ) {
            existing.close(1000, "Reconnecting");
          }
        } catch {
          // ignore
        }
        socketRef.current = null;
      }

      lastConnectRef.current = now;
      stateRef.current.isConnecting = true;
      reconnectAttemptsRef.current += 1;

      if (reconnectAttemptsRef.current > 1) {
        setGameState((prev) => ({ ...prev, reconnecting: true }));
      }

      try {
        const res = await fetch(API.endpoints.wsToken, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
        });
        const data = await res.json();
        if (!res.ok || !data.ok || !data.token) {
          throw new Error("Failed to obtain WebSocket token");
        }

        const wsUrl = API.ws.game(tableId, data.token);
        ws = new WebSocket(wsUrl);
        socketRef.current = ws;

        ws.onopen = () => {
          stateRef.current.isConnecting = false;
          reconnectAttemptsRef.current = 0;

          setGameState((prev) => ({
            ...prev,
            connected: true,
            reconnecting: false,
          }));
        };

        ws.onmessage = (event) => {
          let msg;
          try {
            msg = JSON.parse(event.data);
          } catch {
            onErrorRef.current?.("Invalid WS message");
            return;
          }

          const type = msg.type || msg.event;

          switch (type) {
            case "STATE_SYNC":
              handleStateSync(msg);
              break;
            case "STATE_DIFF":
              handleStateDiff(msg);
              break;
            case "STATE_PRIVATE":
              handleStatePrivate(msg);
              break;
            case "hand_end":
              handleHandEnd(msg);
              break;
            case "hand_start":
              handleHandStart(msg);
              break;
            case "match_end":
              handleMatchEnd(msg);
              break;
            case "PLAYER_CONNECTED":
              handlePlayerConnected(msg);
              break;
            case "PLAYER_AWAY":
            case "PLAYER_DISCONNECTED":
              handlePlayerAway(msg);
              break;
            case "CHAT":
              appendChatMessage(msg);
              break;
            case "CHAT_HISTORY":
              replaceChatHistory(msg);
              break;
            case "pong":
              break;
            case "ERROR":
            case "error":
              onErrorRef.current?.(
                msg.message || msg.error || "Unknown server error"
              );
              break;
            default:
              break;
          }
        };

        ws.onclose = (ev) => {
          stateRef.current.isConnecting = false;

          if (ev.code === 1000 || !stateRef.current.isMounted) {
            setGameState((prev) => ({
              ...prev,
              connected: false,
              reconnecting: false,
            }));
            return;
          }

          if (stateRef.current.isMounted) {
            setGameState((prev) => ({
              ...prev,
              connected: false,
              reconnecting: true,
            }));
            reconnectTimeout = setTimeout(init, 3000);
          }
        };

        ws.onerror = (error) => {
          console.error("[useGameSocket] WebSocket error:", error);
          onErrorRef.current?.("WebSocket connection error");
        };

        pingInterval = setInterval(() => {
          const wsCurrent = socketRef.current;
          if (wsCurrent && wsCurrent.readyState === WebSocket.OPEN) {
            try {
              wsCurrent.send(JSON.stringify({ type: "ping" }));
            } catch {
              clearInterval(pingInterval);
            }
          }
        }, 30000);
      } catch (err) {
        stateRef.current.isConnecting = false;
        onErrorRef.current?.(err.message || "Connection failed");

        setGameState((prev) => ({ ...prev, reconnecting: true }));

        if (stateRef.current.isMounted) {
          reconnectTimeout = setTimeout(init, 3000);
        }
      }
    };

    init();

    return () => {
      stateRef.current.isMounted = false;

      if (pingInterval) clearInterval(pingInterval);
      if (reconnectTimeout) clearTimeout(reconnectTimeout);

      if (ws) {
        try {
          ws.close(1000, "Component unmount");
        } catch {
          // ignore
        }
      }

      socketRef.current = null;
    };
  }, [tableId, appendChatMessage, replaceChatHistory]);

  // ---------------------------------------------------------------------------
  // Public API return
  // ---------------------------------------------------------------------------
  return {
    gameState,
    sendAction,
    sendChat,
    sendNextHand,
    requestSync,
    disconnect,
    toggleChat,
    connected: gameState.connected,
    reconnecting: gameState.reconnecting,
  };
}

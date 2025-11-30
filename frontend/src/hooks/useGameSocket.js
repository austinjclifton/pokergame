// frontend/src/hooks/useGameSocket.js
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

    // Hand result summary for overlay
    handSummary: null,

    // Full match-level state
    matchEnded: false,
    matchResult: null,
  };
}

/**
 * useGameSocket – WebSocket manager for poker game
 */
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
      } catch {}
    }
    socketRef.current = null;

    setGameState((prev) => ({
      ...prev,
      connected: false,
      reconnecting: false,
    }));
  }, []);

  // ---------------------------------------------------------------------------
  // Chat helpers
  // ---------------------------------------------------------------------------

  const appendChatMessage = useCallback((msg) => {
    const createdAt = msg.created_at || new Date().toISOString();
    const time =
      msg.time ||
      new Date(createdAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });

    setGameState((prev) => ({
      ...prev,
      chatMessages: [...prev.chatMessages, { from: msg.from, msg: msg.msg, time, created_at: createdAt }],
    }));
  }, []);

  const replaceChatHistory = useCallback((msg) => {
    const messages = (msg.messages || []).map((m) => {
      const createdAt = m.created_at || new Date().toISOString();
      const time =
        m.time ||
        new Date(createdAt).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });

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

    // Build visual seat entries from server state
    const buildSeatsFromState = (state, prevSeats = []) => {
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
          cards: [],
          handRank: p.handRank,
          handDescription: p.handDescription,
        });
      }

      seats.sort((a, b) => a.seat_no - b.seat_no);
      return seats;
    };

    // STATE_SYNC full snapshot
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

    // STATE_DIFF incremental updates
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

    // STATE_PRIVATE (hole cards, legal actions)
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

    // hand_end event from backend
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

    // New hand broadcast
    const handleHandStart = (msg) => {
      if (matchEndedRef.current) return;
    
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

    // match_end event (final winner)
    // match_end event (final winner)
  const handleMatchEnd = (msg) => {
    // Freeze all further activity
    matchEndedRef.current = true;

    // The last full hand summary from hand_end
    const finalHand = lastHandSummaryRef.current || null;

    // Sanity check: ensure finalHand has all required data
    if (finalHand) {
      if (!Array.isArray(finalHand.board)) {
        console.warn("[useGameSocket] finalHand.board missing");
      }
      if (!finalHand.players || typeof finalHand.players !== "object") {
        console.warn("[useGameSocket] finalHand.players missing");
      }
      if (!Array.isArray(finalHand.winners)) {
        console.warn("[useGameSocket] finalHand.winners missing");
      }
    }

    // Build match summary that the Match Summary Page will use
    const matchSummary = {
      tableId,
      matchEnded: true,
      winner: msg.winner,
      loser: msg.loser,
      finalHand,        // Full hand summary (board + players + winners)
      endedAt: Date.now(),
    };

    // Update the game state but DO NOT wipe any card info
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

      // KEEP THESE — the summary screen reads them
      community: prev.community,
      seats: prev.seats,
      handSummary: prev.handSummary,
      holeCards: prev.holeCards,
    }));

    // Save to localStorage (for summary page redirect)
    try {
      localStorage.setItem(
        `matchSummary_${tableId}`,
        JSON.stringify(matchSummary)
      );
    } catch (e) {
      console.error("Failed to save match summary:", e);
    }

    // Navigate to summary page
    if (onMatchEndRef.current) {
      onMatchEndRef.current(tableId);
    } else {
      window.location.href = `/match/${tableId}/summary`;
    }
  };

    const handlePlayerConnected = (msg) => {
      const seatNo = msg.seat_no;
      if (seatNo == null) return;

      setGameState((prev) => ({
        ...prev,
        seats: prev.seats.map((s) =>
          s.seat_no === seatNo ? { ...s, status: "active", disconnected: false } : s
        ),
      }));
    };

    const handlePlayerAway = (msg) => {
      const seatNo = msg.seat_no;
      if (seatNo == null) return;

      setGameState((prev) => ({
        ...prev,
        seats: prev.seats.map((s) =>
          s.seat_no === seatNo ? { ...s, status: "away", disconnected: true } : s
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

      // Close existing WS
      if (socketRef.current) {
        try {
          const existing = socketRef.current;
          if (
            existing.readyState === WebSocket.OPEN ||
            existing.readyState === WebSocket.CONNECTING
          ) {
            existing.close(1000, "Reconnecting");
          }
        } catch {}
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
              onErrorRef.current?.(msg.message || msg.error || "Unknown server error");
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

        ws.onerror = () => {};

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
        } catch {}
      }

      socketRef.current = null;
    };
  }, [
    tableId,
    appendChatMessage,
    replaceChatHistory,
  ]);

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
    connected: gameState.connected,
    reconnecting: gameState.reconnecting,
  };
}

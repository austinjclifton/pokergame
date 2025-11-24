// frontend/src/hooks/useGameSocket.js
import { useEffect, useRef, useState, useCallback } from "react";
import API from "../config/api";

// Helper: initial state factory (future-proof: easy to extend)
function createInitialGameState() {
  return {
    tableId: null,
    gameId: null,
    gameVersion: 0,

    // Public table state
    community: [],    // board
    seats: [],        // [{ seat_no, user_id, name, username, stack, bet, status, ... }]
    pots: [],         // [{ amount }]
    pot: 0,
    currentBet: 0,
    actionSeat: null,
    currentTurn: null,
    phase: "WAITING",

    // Private state
    mySeat: null,
    holeCards: [],
    legalActions: [],

    // Connection state
    connected: false,
    reconnecting: false,

    // Chat
    chatMessages: [],

    // Hand summary (used by UI overlay)
    handSummary: null,

    // Legacy flag – no longer drives UI visibility
    showSummary: false,
  };
}

/**
 * useGameSocket
 * WebSocket hook for poker table connections.
 *
 * @param {string|number} tableId
 * @param {(err: string) => void} onError
 */
export default function useGameSocket(tableId, onError) {
  const socketRef = useRef(null);
  const stateRef = useRef({ isMounted: false, isConnecting: false });
  const lastConnectRef = useRef(0);
  const reconnectAttemptsRef = useRef(0);
  const maxReconnectAttempts = 10;
  const onErrorRef = useRef(onError);

  // Keep latest onError in a ref, no re-renders
  useEffect(() => {
    onErrorRef.current = onError;
  }, [onError]);

  const [gameState, setGameState] = useState(createInitialGameState);

  // ----------------------------
  // Public API: send action/chat
  // ----------------------------

  const sendAction = useCallback(
    (action, amount = 0) => {
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
        onErrorRef.current?.(
          `Failed to send action: ${err instanceof Error ? err.message : String(err)}`
        );
      }
    },
    [gameState.gameVersion]
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
      ws.send(
        JSON.stringify({
          type: "chat",
          msg: text,
        })
      );
    } catch (err) {
      onErrorRef.current?.(
        `Failed to send chat message: ${err instanceof Error ? err.message : String(err)}`
      );
    }
  }, []);

  const sendNextHand = useCallback(() => {
    const ws = socketRef.current;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      onErrorRef.current?.("Not connected to game server");
      return;
    }

    try {
      ws.send(JSON.stringify({ type: "next_hand" }));
    } catch (err) {
      onErrorRef.current?.(
        `Failed to send next_hand request: ${err instanceof Error ? err.message : String(err)}`
      );
    }
  }, []);

  // Best-effort sync request (no server-side sync message type yet)
  const requestSync = useCallback(() => {
    const ws = socketRef.current;
    if (!ws || ws.readyState !== WebSocket.OPEN) {
      // Let the effect reconnect
      if (tableId) {
        stateRef.current.isMounted = true;
        lastConnectRef.current = 0;
        reconnectAttemptsRef.current = 0;
      }
      return;
    }

    try {
      // Use chat_history as a cheap "ping" to confirm connection
      ws.send(JSON.stringify({ type: "chat_history" }));
    } catch (err) {
      onErrorRef.current?.(
        `Failed to request sync: ${err instanceof Error ? err.message : String(err)}`
      );
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

  // ----------------------------
  // Chat helpers
  // ----------------------------

  const appendChatMessage = useCallback((msg) => {
    const createdAt =
      msg.created_at || new Date().toISOString();
    const time =
      msg.time ||
      new Date(createdAt).toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
      });

    const newMsg = {
      from: msg.from,
      msg: msg.msg,
      time,
      created_at: createdAt,
    };

    setGameState((prev) => ({
      ...prev,
      chatMessages: [...(prev.chatMessages || []), newMsg],
    }));
  }, []);

  const replaceChatHistory = useCallback((msg) => {
    const messages = (msg.messages || []).map((m) => {
      const createdAt =
        m.created_at || new Date().toISOString();
      const time =
        m.time ||
        new Date(createdAt).toLocaleTimeString([], {
          hour: "2-digit",
          minute: "2-digit",
        });

      return {
        from: m.from,
        msg: m.msg,
        time,
        created_at: createdAt,
      };
    });

    setGameState((prev) => ({
      ...prev,
      chatMessages: messages,
    }));
  }, []);

  // ----------------------------
  // Main WS effect
  // ----------------------------

  useEffect(() => {
    if (!tableId) {
      return;
    }

    let ws;
    let pingInterval;
    let reconnectTimeout;

    stateRef.current.isMounted = true;

    const buildSeatsFromState = (state, prevSeats = []) => {
      const players = state.players || {};
      const seats = [];

      const prevSeatMap = new Map(prevSeats.map((s) => [s.seat_no, s]));

      Object.keys(players).forEach((seatKey) => {
        const player = players[seatKey];
        const seatNo = parseInt(seatKey, 10);
        const prev = prevSeatMap.get(seatNo);

        let status;
        if (player.folded) status = "folded";
        else if (player.allIn) status = "all_in";
        else status = "active";

        // If previously marked disconnected/away, preserve that visual if still relevant
        if (prev?.status === "disconnected" || prev?.status === "away") {
          if (status === "active") {
            status = prev.status;
          }
        }

        seats.push({
          seat_no: seatNo,
          user_id:
            player.user_id !== undefined
              ? player.user_id
              : prev?.user_id || null,
          name:
            player.username ||
            prev?.name ||
            `Seat ${seatNo}`,
          username:
            player.username ||
            prev?.username ||
            null,
          stack: player.stack ?? 0,
          bet: player.bet ?? 0,
          status,
          cards: player.cards || [],
          handRank: player.handRank,
          handDescription: player.handDescription,
        });
      });

      seats.sort((a, b) => a.seat_no - b.seat_no);
      return seats;
    };

    const handleStateSync = (msg) => {
      const state = msg.state || {};
      const gameId = msg.game_id ?? null;
      const version = msg.version ?? 0;

      setGameState((prev) => ({
        ...prev,
        tableId,
        gameId,
        gameVersion: version,

        community: state.board || [],
        seats: buildSeatsFromState(state),
        pots: state.pots || [{ amount: state.pot || 0 }],
        pot: state.pot || 0,
        currentBet: state.currentBet || 0,
        actionSeat: state.actionSeat ?? null,
        currentTurn: state.actionSeat ?? null,
        phase: (state.phase || "preflop").toString().toUpperCase(),

        // Keep private state until STATE_PRIVATE arrives
        connected: true,
        reconnecting: false,
      }));
    };

    const handleStateDiff = (msg) => {
      const state = msg.state || {};
      const version = msg.version ?? null;

      setGameState((prev) => ({
        ...prev,
        gameVersion: version ?? prev.gameVersion,

        community:
          state.board !== undefined ? state.board : prev.community,
        seats: buildSeatsFromState(state, prev.seats),
        pots:
          state.pots ||
          [{ amount: state.pot !== undefined ? state.pot : prev.pot }],
        pot:
          state.pot !== undefined ? state.pot : prev.pot,
        currentBet:
          state.currentBet !== undefined
            ? state.currentBet
            : prev.currentBet,
        actionSeat:
          state.actionSeat !== undefined
            ? state.actionSeat
            : prev.actionSeat,
        currentTurn:
          state.actionSeat !== undefined
            ? state.actionSeat
            : prev.currentTurn,
        phase:
          state.phase !== undefined
            ? state.phase.toString().toUpperCase()
            : prev.phase,

        // IMPORTANT: do NOT touch holeCards / legalActions here.
        // They are driven exclusively by STATE_PRIVATE.
      }));
    };

    const handleStatePrivate = (msg) => {
      const privateState = msg.state || {};

      setGameState((prev) => ({
        ...prev,
        mySeat:
          privateState.mySeat ??
          msg.seat ??
          prev.mySeat,
        holeCards:
          privateState.myCards ||
          privateState.cards ||
          prev.holeCards,
        legalActions:
          privateState.legalActions ?? prev.legalActions,
      }));
    };

    const handleHandEnd = (msg) => {
      const summary = msg.summary || msg.payload || msg;

      // Store the summary for the overlay.
      // Overlay visibility is now controlled entirely by the UI (GameTable),
      // not by showSummary from the server.
      setGameState((prev) => ({
        ...prev,
        handSummary: summary,
        // DO NOT set showSummary here; frontend controls overlay.
      }));

      // After short delay, automatically request next hand.
      // The table game state will progress behind the overlay.
      setTimeout(() => {
        const wsCurrent = socketRef.current;
        if (
          wsCurrent &&
          wsCurrent.readyState === WebSocket.OPEN
        ) {
          try {
            wsCurrent.send(JSON.stringify({ type: "next_hand" }));
          } catch (err) {
            onErrorRef.current?.(
              `Failed to send next_hand request: ${
                err instanceof Error ? err.message : String(err)
              }`
            );
          }
        }
      }, 5000);
    };

    const handleHandStart = (msg) => {
      const state = msg.state || {};
      const version = msg.version ?? null;
    
      setGameState((prev) => {
        const newSeats = buildSeatsFromState(state, prev.seats);
    
        return {
          ...prev,
          gameVersion: version ?? prev.gameVersion,
          community: state.board || [],
          seats: newSeats,
          pots: state.pots || [{ amount: state.pot || 0 }],
          pot: state.pot || 0,
          currentBet: state.currentBet || 0,
          actionSeat: state.actionSeat ?? null,
          currentTurn: state.actionSeat ?? null,
          phase: (state.phase || "preflop").toString().toUpperCase(),
    
          // ❗ KEEP SUMMARY VISIBLE UNTIL USER DISMISSES
          // handSummary: prev.handSummary,
          // showSummary: prev.showSummary,
        };
      });
    };    

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

    const init = async () => {
      const now = Date.now();
      if (stateRef.current.isConnecting || !stateRef.current.isMounted) {
        return;
      }
      if (now - lastConnectRef.current < 1000) {
        return;
      }

      if (reconnectAttemptsRef.current >= maxReconnectAttempts) {
        onErrorRef.current?.("Max reconnection attempts reached");
        return;
      }

      // Close any existing connection cleanly before reconnect
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
        setGameState((prev) => ({
          ...prev,
          reconnecting: true,
        }));
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

          if (process.env.NODE_ENV === "development") {
            console.log("[WS OPEN] Table #" + tableId);
          }
        };

        ws.onmessage = (event) => {
          let msg;
          try {
            msg = JSON.parse(event.data);
          } catch (err) {
            if (process.env.NODE_ENV === "development") {
              console.error("Failed to parse WS message:", err);
            }
            onErrorRef.current?.("Received invalid message from server");
            return;
          }

          const messageType = msg.type || msg.event;

          switch (messageType) {
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
            case "PLAYER_CONNECTED":
              handlePlayerConnected(msg);
              break;
            case "PLAYER_AWAY":
            case "PLAYER_DISCONNECTED": // backward compatibility
              handlePlayerAway(msg);
              break;
            case "CHAT":
              appendChatMessage(msg);
              break;
            case "CHAT_HISTORY":
              replaceChatHistory(msg);
              break;
            case "pong":
              // heartbeat
              break;
            case "ERROR":
            case "error":
              onErrorRef.current?.(
                msg.message ||
                  msg.error ||
                  msg.code ||
                  "Unknown server error"
              );
              break;
            default:
              if (process.env.NODE_ENV === "development") {
                console.warn("Unknown WS message type:", messageType, msg);
              }
          }
        };

        ws.onclose = (ev) => {
          stateRef.current.isConnecting = false;

          if (process.env.NODE_ENV === "development") {
            console.log(
              "[WS CLOSE] Table #" + tableId,
              "Code:",
              ev.code,
              "Reason:",
              ev.reason
            );
          }

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

        ws.onerror = (err) => {
          if (process.env.NODE_ENV === "development") {
            console.error("WebSocket error:", err);
          }
          // onclose will handle reconnection
        };

        // Heartbeat
        pingInterval = setInterval(() => {
          const wsCurrent = socketRef.current;
          if (!wsCurrent) return;
          if (wsCurrent.readyState !== WebSocket.OPEN) return;

          try {
            wsCurrent.send(JSON.stringify({ type: "ping" }));
            if (process.env.NODE_ENV === "development") {
              console.log("[WS PING] Table #" + tableId);
            }
          } catch {
            clearInterval(pingInterval);
          }
        }, 30000);
      } catch (err) {
        stateRef.current.isConnecting = false;
        const msg =
          err instanceof Error ? err.message : "Connection failed";
        onErrorRef.current?.(msg);

        setGameState((prev) => ({
          ...prev,
          reconnecting: true,
        }));

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

// frontend/src/hooks/useLobbySocket.js
import { useEffect, useRef } from "react";
import API from "../config/api";

/**
 * useLobbySocket
 * Handles connection, reconnection, pings, and incoming WS events.
 * Delegates state updates to callbacks provided by the parent.
 */
export default function useLobbySocket({ onMessage, onPlayers, onConnected }) {
  const socketRef = useRef(null);
  const stateRef = useRef({ isMounted: false, isConnecting: false });
  const lastConnectRef = useRef(0);
  const callbacksRef = useRef({ onMessage, onPlayers, onConnected });

  useEffect(() => {
    callbacksRef.current = { onMessage, onPlayers, onConnected };
  }, [onMessage, onPlayers, onConnected]);

  useEffect(() => {
    let ws, pingInterval, reconnectTimeout;
    const connectionState = stateRef.current;
    connectionState.isMounted = true;

    const init = async () => {
      const now = Date.now();
      if (connectionState.isConnecting || !connectionState.isMounted) return;
      if (now - lastConnectRef.current < 1000) return;
      lastConnectRef.current = now;
      connectionState.isConnecting = true;

      try {
        const res = await fetch(API.endpoints.wsToken, {
          method: "POST",
          credentials: "include",
          headers: { "Content-Type": "application/json" },
        });
        const data = await res.json();
        if (!res.ok || !data.ok || !data.token) throw new Error("Bad ws_token");

        ws = new WebSocket(API.ws.lobby(data.token));
        socketRef.current = ws;

        ws.onopen = () => {
          connectionState.isConnecting = false;
          callbacksRef.current.onConnected?.();
        };

        ws.onmessage = (event) => {
          try {
            const msg = JSON.parse(event.data);
            if (msg.type === "online_users") callbacksRef.current.onPlayers?.(msg.users || []);
            else callbacksRef.current.onMessage?.(msg);
          } catch {
            // Invalid message format - silently ignore
          }
        };

        ws.onclose = (ev) => {
          connectionState.isConnecting = false;
          if (connectionState.isMounted && ev.code !== 1000) {
            reconnectTimeout = setTimeout(init, 5000);
          }
        };

        ws.onerror = () => {
          // WebSocket error - connection will attempt to reconnect
        };

        // Ping every 30s
        pingInterval = setInterval(() => {
          if (ws.readyState === WebSocket.OPEN)
            ws.send(JSON.stringify({ type: "ping" }));
        }, 30000);
      } catch {
        connectionState.isConnecting = false;
        // Connection will retry automatically
      }
    };

    init();

    return () => {
      connectionState.isMounted = false;
      clearInterval(pingInterval);
      clearTimeout(reconnectTimeout);
      ws?.close(1000, "Unmount");
    };
  }, []);

  return socketRef;
}

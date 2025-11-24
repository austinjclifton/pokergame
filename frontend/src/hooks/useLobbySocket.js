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

  useEffect(() => {
    let ws, pingInterval, reconnectTimeout;
    stateRef.current.isMounted = true;

    const init = async () => {
      const now = Date.now();
      if (stateRef.current.isConnecting || !stateRef.current.isMounted) return;
      if (now - lastConnectRef.current < 1000) return;
      lastConnectRef.current = now;
      stateRef.current.isConnecting = true;

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
          stateRef.current.isConnecting = false;
          onConnected?.();
        };

        ws.onmessage = (event) => {
          try {
            const msg = JSON.parse(event.data);
            if (msg.type === "online_users") onPlayers?.(msg.users || []);
            else onMessage?.(msg);
          } catch (e) {
            // Invalid message format - silently ignore
          }
        };

        ws.onclose = (ev) => {
          stateRef.current.isConnecting = false;
          if (stateRef.current.isMounted && ev.code !== 1000) {
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
      } catch (e) {
        stateRef.current.isConnecting = false;
        // Connection will retry automatically
      }
    };

    init();

    return () => {
      stateRef.current.isMounted = false;
      clearInterval(pingInterval);
      clearTimeout(reconnectTimeout);
      ws?.close(1000, "Unmount");
    };
  }, []);

  return socketRef;
}

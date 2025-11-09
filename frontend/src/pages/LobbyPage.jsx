// frontend/src/pages/LobbyPage.jsx
import React, { useState, useRef, useEffect } from "react";
import { useNavigate } from "react-router-dom";
import useLobbySocket from "../hooks/useLobbySocket";
import LobbyHeader from "../components/lobby/LobbyHeader";
import PlayerList from "../components/lobby/PlayerList";
import ChallengePanel from "../components/lobby/ChallengePanel";
import ChatBox from "../components/lobby/ChatBox";
import API from "../config/api";
import { fetchCsrfToken } from "../utils/csrf";
import "../styles/lobby.css";

export default function LobbyPage({ user, onLogout }) {
  const navigate = useNavigate();
  const [players, setPlayers] = useState([]);
  const [messages, setMessages] = useState([]);
  const [challenges, setChallenges] = useState([]);
  const [showChallenges, setShowChallenges] = useState(false);
  const [pending, setPending] = useState(new Set());
  const messagesEndRef = useRef(null);

  // Filter messages to only show those from the last 12 hours
  const filterRecentMessages = React.useCallback((msgs) => {
    const twelveHoursAgo = Date.now() - 12 * 60 * 60 * 1000;
    return msgs.filter((msg) => {
      // System messages (join/leave) without timestamps are always recent, keep them
      if (msg.system && !msg.created_at) return true;
      
      // If message has a created_at timestamp, check it
      if (msg.created_at) {
        const msgTime = new Date(msg.created_at).getTime();
        return msgTime >= twelveHoursAgo;
      }
      
      // For messages without timestamps, assume they're recent (better to show than hide)
      return true;
    });
  }, []);

  // Fetch challenges from API - use useCallback to avoid dependency issues
  const fetchChallenges = React.useCallback(async () => {
    try {
      const res = await fetch(API.endpoints.challenges, {
        credentials: "include",
      });
      const data = await res.json();
      if (data.ok) {
        setChallenges(data.challenges || []);
      }
    } catch (e) {
      // Failed to fetch challenges - will retry on next update
    }
  }, []);

  // Fetch challenges on mount and when user changes
  useEffect(() => {
    if (user) {
      fetchChallenges();
    }
  }, [user, fetchChallenges]);

  const socketRef = useLobbySocket({
    onPlayers: (users) => {
      // Map users to include status for filtering
      setPlayers((users || []).map((u) => ({ id: u.id, username: u.username, status: u.status || "online" })));
    },
    onConnected: () => {
      setMessages((p) => [...p, { system: true, msg: "Connected to lobby." }]);
      // Fetch challenges when connected
      fetchChallenges();
    },
    onMessage: (msg) => {
      switch (msg.type) {
        case "history":
          // Filter history messages to only show last 12 hours
          const historyMsgs = (msg.messages || []).map((m) => ({
            from: m.from,
            msg: m.msg,
            system: m.system || false,
            time: m.time,
            created_at: m.created_at, // Include timestamp if available
          }));
          setMessages(filterRecentMessages(historyMsgs));
          break;
        case "chat":
          const newMsg = {
            from: msg.system ? "System" : msg.from,
            msg: msg.msg,
            system: msg.system || false,
            time: msg.time || new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
            created_at: msg.created_at || new Date().toISOString(), // Add timestamp for filtering
          };
          setMessages((p) => {
            const updated = [...p, newMsg];
            return filterRecentMessages(updated);
          });
          break;
        case "presence": {
          const id = msg.user?.id;
          const name = msg.user?.username;
          if (!id || !name) break;
          
          if (msg.event === "join") {
            setPlayers((p) => {
              // Don't add if already exists
              if (p.some((x) => x.id === id)) {
                return p;
              }
              // Add new player with status
              return [...p, { id, username: name, status: msg.user?.status || "online" }];
            });
          } else if (msg.event === "leave") {
            setPlayers((p) => p.filter((x) => x.id !== id));
          }
          break;
        }
        case "challenge":
          fetchChallenges();
          // Show a chat notification
          setMessages((p) => [
            ...p,
            {
              system: true,
              msg: `🎮 ${msg.from?.username || "Someone"} challenged you!`,
              time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
            },
          ]);
          // Auto-open challenges panel if closed
          if (!showChallenges) {
            setShowChallenges(true);
          }
          break;
        case "challenge_sent":
        case "challenge_response":
        case "challenge_cancel":
          fetchChallenges();
          break;
      }
    },
  });

  // Scroll chat
  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  // Periodically clean up old messages (older than 12 hours)
  useEffect(() => {
    const cleanupInterval = setInterval(() => {
      setMessages((current) => filterRecentMessages(current));
    }, 60000); // Check every minute

    return () => clearInterval(cleanupInterval);
  }, [filterRecentMessages]);

  const sendChat = (text) => {
    if (!text || !socketRef.current) return;
    socketRef.current.send(JSON.stringify({ type: "chat", msg: text }));
  };

  const handleLogout = async () => {
    try {
      // Fetch CSRF token before logout
      const token = await fetchCsrfToken();

      socketRef.current?.send(JSON.stringify({ type: "logout" }));
      socketRef.current?.close(1000, "User logout");
      
      // Send logout request with CSRF token
      await fetch(API.endpoints.logout, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        credentials: "include",
        body: JSON.stringify({ token }),
      });
      
      onLogout();
      navigate("/login");
    } catch (err) {
      alert("Logout failed: " + (err.message || "Unknown error"));
    }
  };

  return (
    <div className="lobby-page">
      <LobbyHeader
        challenges={challenges}
        showChallenges={showChallenges}
        setShowChallenges={setShowChallenges}
        handleLogout={handleLogout}
        players={players}
      />
      {showChallenges && (
        <ChallengePanel
          challenges={challenges}
          user={user}
          pending={pending}
          setPending={setPending}
          socketRef={socketRef}
        />
      )}
      <PlayerList players={players} user={user} socketRef={socketRef} challenges={challenges} />
      <ChatBox messages={filterRecentMessages(messages)} sendChat={sendChat} ref={messagesEndRef} />
    </div>
  );
}

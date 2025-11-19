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
  const [pendingChallenge, setPendingChallenge] = useState(null); // Track outgoing pending challenge
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

  // Fetch pending challenges on mount to restore state after refresh
  const fetchPendingChallenges = React.useCallback(async () => {
    if (!user) return;
    
    try {
      // Fetch from backend to get authoritative state (database is source of truth)
      const res = await fetch(API.endpoints.challengesPending, {
        credentials: "include",
      });
      const data = await res.json();
      
      if (data.ok) {
        // Update pending challenge state from backend
        if (data.outgoing && data.outgoing.length > 0) {
          const outgoing = data.outgoing[0]; // User can only have one pending outgoing challenge
          setPendingChallenge({
            id: outgoing.id,
            opponent: outgoing.opponent,
            opponent_id: outgoing.opponent_id,
            created_at: outgoing.created_at,
          });
        } else {
          setPendingChallenge(null);
        }
      }
    } catch (e) {
      console.error('[LobbyPage] Failed to fetch pending challenges:', e);
    }
  }, [user]);

  // Fetch challenges on mount and when user changes
  useEffect(() => {
    if (user) {
      fetchChallenges();
      fetchPendingChallenges();
    }
  }, [user, fetchChallenges, fetchPendingChallenges]);

  // No localStorage needed - database is source of truth

  const socketRef = useLobbySocket({
    onPlayers: (users) => {
      // Map users to include status and active_table_id for filtering
      const mapped = (users || []).map((u) => ({ 
        id: u.id, 
        username: u.username, 
        status: u.status || "online",
        active_table_id: u.active_table_id || null
      }));
      
      // Sort: current user always first, then alphabetically by username
      mapped.sort((a, b) => {
        if (a.id === user?.id) return -1;
        if (b.id === user?.id) return 1;
        return a.username.localeCompare(b.username);
      });
      
      setPlayers(mapped);
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
              let updated;
              // Don't add if already exists
              if (p.some((x) => x.id === id)) {
                // Update existing player's info
                updated = p.map(x => x.id === id ? { ...x, username: name, status: msg.user?.status || "online", active_table_id: msg.user?.active_table_id || null } : x);
              } else {
                // Add new player with status and active_table_id
                updated = [...p, { id, username: name, status: msg.user?.status || "online", active_table_id: msg.user?.active_table_id || null }];
              }
              
              // Sort: current user always first, then alphabetically by username
              return updated.sort((a, b) => {
                if (a.id === user?.id) return -1;
                if (b.id === user?.id) return 1;
                return a.username.localeCompare(b.username);
              });
            });
          } else if (msg.event === "leave") {
            setPlayers((p) => {
              const filtered = p.filter((x) => x.id !== id);
              // Re-sort after removing player
              return filtered.sort((a, b) => {
                if (a.id === user?.id) return -1;
                if (b.id === user?.id) return 1;
                return a.username.localeCompare(b.username);
              });
            });
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
          // Challenge was sent successfully - refresh challenges list
          fetchChallenges();
          // Update pending challenge state if challenge_id is provided
          if (msg.challenge_id && msg.to) {
            setPendingChallenge({
              id: msg.challenge_id,
              opponent: msg.to.username,
              opponent_id: msg.to.id,
              created_at: new Date().toISOString(),
            });
          }
          break;
        case "challenge_response":
        case "challenge_cancel":
        case "challenge_resolved":
          // Challenge was responded to, cancelled, or resolved - clear pending state
          setPendingChallenge(null);
          fetchChallenges();
          break;
        case "error":
          // Handle errors from challenge attempts
          if (msg.error) {
            console.error("Challenge error:", msg.error);
            // Show error message to user
            setMessages((p) => [
              ...p,
              {
                system: true,
                msg: `❌ Error: ${msg.error}`,
                time: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }),
                created_at: new Date().toISOString(),
              },
            ]);
            // Clear pending challenge state if challenge failed
            setPendingChallenge(null);
            // Refresh challenges to reset button state if challenge failed
            fetchChallenges();
          }
          break;
        case "GAME_START":
          // Redirect to game page when challenge is accepted and game starts
          console.log("GAME_START message received:", msg);
          if (msg.table_id) {
            fetchChallenges(); // Update challenge list before redirect
            setTimeout(() => {
              navigate(`/game/${msg.table_id}`);
            }, 1000); // Small delay to show the message
          } else {
            console.warn("GAME_START but no table_id:", msg);
          }
          break;
        case "challenge_accepted":
          // Legacy handler - redirect to game page when challenge is accepted
          console.log("Challenge accepted message received:", msg);
          if (msg.table_id) {
            fetchChallenges(); // Update challenge list before redirect
            setTimeout(() => {
              navigate(`/game/${msg.table_id}`);
            }, 1000); // Small delay to show the message
          } else {
            console.warn("Challenge accepted but no table_id:", msg);
          }
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
      <PlayerList 
        players={players} 
        user={user} 
        socketRef={socketRef} 
        challenges={challenges}
        pendingChallenge={pendingChallenge}
        setPendingChallenge={setPendingChallenge}
        navigate={navigate}
      />
      <ChatBox messages={filterRecentMessages(messages)} sendChat={sendChat} ref={messagesEndRef} />
    </div>
  );
}

// src/App.jsx
import React, { useState, useEffect } from "react";
import { BrowserRouter as Router, Routes, Route, Navigate } from "react-router-dom";
import LoginPage from "./pages/LoginPage";
import RegisterPage from "./pages/RegisterPage";
import LobbyPage from "./pages/LobbyPage";
import GamePage from "./pages/GamePage";
import Card from "./components/cards/Card";
import API from "./config/api";

export default function App() {
  const [user, setUser] = useState(null);
  const [checking, setChecking] = useState(true);

  // ---------------- LOGOUT HANDLER ----------------
  const handleLogout = () => {
    setUser(null);
  };

  // ---------------- SESSION CHECK ----------------
  useEffect(() => {
    const checkSession = async () => {
      try {
        const res = await fetch(API.endpoints.me, {
          credentials: "include",
        });

        // 401 → no session
        if (res.status === 401) {
          setUser(null);
          return;
        }

        const data = await res.json();
        if (data.ok && data.user) {
          setUser({
            id: data.user.id ?? data.user.user_id,
            username: data.user.username,
            email: data.user.email,
            session_id: data.user.session_id
          });
        
          console.log("Logged in as", data.user.username);
        } else {
          setUser(null);
        }        
      } catch (err) {
        setUser(null);
      } finally {
        setChecking(false);
      }
    };

    checkSession();
  }, []);

  if (checking)
    return <p style={{ color: "white", textAlign: "center" }}>Checking session...</p>;

  // ---------------- ROUTES ----------------
  return (
    <Router>
      <Routes>
        {/* Redirect root based on auth state */}
        <Route path="/" element={<Navigate to={user ? "/lobby" : "/login"} replace />} />

        {/* LOGIN + REGISTER: redirect if already logged in */}
        <Route
          path="/login"
          element={user ? <Navigate to="/lobby" replace /> : <LoginPage onLogin={setUser} />}
        />

        <Route
          path="/register"
          element={user ? <Navigate to="/lobby" replace /> : <RegisterPage />}
        />

        {/* LOBBY: protected route */}
        <Route
          path="/lobby"
          element={user ? <LobbyPage user={user} onLogout={handleLogout} /> : <Navigate to="/login" replace />}
        />

        {/* GAME: protected route */}
        <Route
          path="/game/:tableId"
          element={user ? <GamePage user={user} /> : <Navigate to="/login" replace />}
        />

        {/* CARD TEST PAGE */}
        <Route
          path="/card-test"
          element={
            <div
              style={{
                display: "flex",
                gap: "1rem",
                justifyContent: "center",
                alignItems: "center",
                minHeight: "100vh",
                background:
                  "radial-gradient(circle at 50% 50%, #0b2a1a 0%, #000 90%)",
              }}
            >
              <Card suit="hearts" rank="3" />
              <Card suit="spades" rank="K" />
              <Card suit="diamonds" rank="10" />
              <Card suit="clubs" rank="Q" />
              <Card suit="spades" rank="A" revealed={false} />
            </div>
          }
        />

        {/* FALLBACK 404 */}
        <Route
          path="*"
          element={<h1 style={{ color: "white", textAlign: "center" }}>404 Not Found</h1>}
        />
      </Routes>
    </Router>
  );
}

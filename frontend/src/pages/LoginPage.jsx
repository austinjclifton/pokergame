// src/pages/LoginPage.jsx
// ------------------------------------------------------------------------
// Login page for PokerGame
// ------------------------------------------------------------------------

import React, { useEffect, useState } from "react";
import { useNavigate, Link, useLocation } from "react-router-dom";
import LoginForm from "../components/LoginForm";
import "../styles/login.css";

export default function LoginPage({ onLogin }) {
  const navigate = useNavigate();
  const location = useLocation();

  // Banner state
  const [successBanner, setSuccessBanner] = useState("");

  // Detect ?registered=1 in URL and show banner
  useEffect(() => {
    const params = new URLSearchParams(location.search);
    if (params.get("registered") === "1") {
      setSuccessBanner("Registration successful! You may now log in.");

      // Remove the flag from URL so it doesn't reappear on refresh
      params.delete("registered");
      navigate({ search: params.toString() }, { replace: true });

      // Auto-hide after 4 seconds
      setTimeout(() => setSuccessBanner(""), 4000);
    }
  }, [location.search, navigate]);

  // Called when LoginForm reports successful authentication
  const handleLogin = (user) => {
    onLogin?.(user);
    setTimeout(() => navigate("/lobby"), 200);
  };

  return (
    <div className="login-page">
      {/* Success Banner */}
      {successBanner && (
        <div className="success-banner-overlay">
          <span className="success-icon">✅</span>
          <span className="success-text">{successBanner}</span>
        </div>
      )}

      {/* Application title */}
      <h1 className="login-title">PokerGame</h1>

      {/* Login form calling /api/login.php */}
      <LoginForm onLogin={handleLogin} />

      {/* Navigation for new users */}
      <p className="login-meta">
        Don’t have an account?{" "}
        <Link to="/register">Register</Link>
      </p>
    </div>
  );
}

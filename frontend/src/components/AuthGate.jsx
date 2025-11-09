// src/components/AuthGate.jsx
// -----------------------------------------------------------------------------
// AuthGate — a lightweight session guard for protected routes.
//
// Responsibilities:
//   • On mount, calls /api/me.php to verify session cookie validity.
//   • If session valid → navigate("/lobby").
//   • If invalid → render the <LoginPage /> component.
//   • Displays minimal "checking session" feedback while waiting.
//
// Not responsible for:
//   • Actual login/logout logic (handled by AuthService + backend).
//   • Persistent user context (that can be added later if needed).
//
// This component ensures the frontend doesn’t render protected pages
// until the user’s PHP session is confirmed as valid.
// -----------------------------------------------------------------------------

import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import LoginPage from "../pages/LoginPage.jsx";

export default function AuthGate() {
  const [checked, setChecked] = useState(false); // becomes true after backend check
  const [loggedIn, setLoggedIn] = useState(false); // true if session valid
  const navigate = useNavigate();

  useEffect(() => {
    const checkSession = async () => {
      try {
        const res = await fetch("/api/me.php", {
          method: "GET",
          credentials: "include", // ensures cookie sent
        });

        if (res.ok) {
          const data = await res.json();
          if (data?.ok && data?.user) {
            setLoggedIn(true);
            // Delay slightly to prevent visible page flash
            setTimeout(() => navigate("/lobby"), 150);
            return;
          }
        }

        // No valid session
        setLoggedIn(false);
      } catch (err) {
        setLoggedIn(false);
      } finally {
        setChecked(true); // always mark completion
      }
    };

    checkSession();
  }, [navigate]);

  // ---------------- RENDER STATES ----------------

  if (!checked) {
    return (
      <div style={{ color: "#fff", textAlign: "center" }}>
        Checking session...
      </div>
    );
  }

  if (loggedIn) {
    return (
      <div style={{ color: "#fff", textAlign: "center" }}>
        ✅ Session valid — redirecting to lobby...
      </div>
    );
  }

  // No session → render login
  return <LoginPage />;
}

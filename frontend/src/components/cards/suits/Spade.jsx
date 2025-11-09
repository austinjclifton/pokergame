import React from "react";

export default function Spade({ size = 24 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      xmlns="http://www.w3.org/2000/svg"
      fill="currentColor"
    >
      <circle cx="35" cy="65" r="20" />
      <circle cx="65" cy="65" r="20" />
      <polygon points="15,60 50,5 85,60" />
      <rect x="45" y="60" width="10" height="40" />
    </svg>
  );
}

import React from "react";

export default function Heart({ size = 24 }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 100 100"
      xmlns="http://www.w3.org/2000/svg"
      fill="currentColor"
    >
      <circle cx="38" cy="35" r="22" />
      <circle cx="62" cy="35" r="22" />
      <polygon points="18,46 50,88 82,46" />
    </svg>
  );
}

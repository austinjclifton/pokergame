import React from "react";

export default function CardBack({ width = 100, height = 150 }) {
  return (
    <svg
      width={width}
      height={height}
      viewBox={`0 0 ${width} ${height}`}
      xmlns="http://www.w3.org/2000/svg"
      style={{
        borderRadius: "10px",
        boxShadow: "0 3px 8px rgba(0,0,0,0.5)",
        margin: "0 0.4rem",
      }}
    >
      {/* base royal blue background */}
      <rect
        x="0"
        y="0"
        width={width}
        height={height}
        rx="8"
        ry="8"
        fill="#124E78"
        stroke="#000"
        strokeWidth="0.8"
      />

      {/* gold border */}
      <rect
        x="5"
        y="5"
        width={width - 10}
        height={height - 10}
        rx="6"
        ry="6"
        fill="none"
        stroke="#F2BB05"
        strokeWidth="1.4"
        opacity="0.8"
      />

      {/* square lattice pattern */}
      <pattern
        id="royal-pattern"
        width="12"
        height="12"
        patternUnits="userSpaceOnUse"
      >
        <path
          d="M7 0 L14 7 L7 14 L0 7 Z"
          stroke="#F2BB05"
          strokeWidth="0.8"
          fill="none"
          opacity="0.35"
        />
      </pattern>
      <rect
        x="5"
        y="5"
        width={width - 10}
        height={height - 10}
        fill="url(#royal-pattern)"
      />

      {/* central "logo" */}
      <g transform={`translate(${width / 2}, ${height / 2})`}>

        {/* outer circle */}
        <circle
          r={width * 0.18}
          fill="none"
          stroke="#F2BB05"
          strokeWidth="1.5"
        />

        {/* sub-circle */}
        <circle
          r={width * 0.12}
          fill="rgba(255,255,255,0.08)"
          stroke="#F2BB05"
          strokeWidth="0.75"
        />

        {/* orange triangle */}
        <polygon
          points={`
            ${-width * 0.07},${width * 0.02}
            0,${-width * 0.07}
            ${width * 0.07},${width * 0.02}
          `}
          fill="#F2BB05"
        />

        {/* small white dot */}
        <circle
          cx="0"
          cy={-width * 0.08}
          r={width * 0.012}
          fill="#ffffff"
        />
      </g>
    </svg>
  );
}

import React, { forwardRef, useState } from "react";

// Helper function to format timestamp with date
const formatTimestamp = (msg) => {
  let msgDate = null;
  
  // Try to parse created_at if available (preferred - most accurate)
  if (msg.created_at) {
    msgDate = new Date(msg.created_at);
  } else if (msg.time) {
    // Parse time string (backend sends 24-hour format "HH:MM", but frontend might format as "H:MM AM/PM")
    // Assume it's today if we only have time
    msgDate = new Date();
    const timeMatch = msg.time.match(/(\d{1,2}):(\d{2})/);
    if (timeMatch) {
      let hours = parseInt(timeMatch[1], 10);
      const minutes = parseInt(timeMatch[2], 10);
      // Handle 12-hour format (AM/PM)
      if (msg.time.toLowerCase().includes('pm') && hours !== 12) {
        hours += 12;
      } else if (msg.time.toLowerCase().includes('am') && hours === 12) {
        hours = 0;
      }
      // If no AM/PM and hours > 12, assume 24-hour format
      msgDate.setHours(hours, minutes, 0, 0);
    }
  } else {
    // No timestamp available, assume now
    msgDate = new Date();
  }
  
  // Format time
  const timeStr = msgDate.toLocaleTimeString([], { 
    hour: '2-digit', 
    minute: '2-digit',
    hour12: true 
  });
  
  // Format date in MM/DD/YY format
  const month = String(msgDate.getMonth() + 1).padStart(2, '0');
  const day = String(msgDate.getDate()).padStart(2, '0');
  const year = String(msgDate.getFullYear()).slice(-2);
  const dateStr = `${month}/${day}/${year}`;
  
  // Always show date in MM/DD/YY format
  return `${timeStr} ${dateStr}`;
};

const ChatBox = forwardRef(({ messages, sendChat }, messagesEndRef) => {
  const [input, setInput] = useState("");
  const handleSend = () => {
    if (!input.trim()) return;
    sendChat(input);
    setInput("");
  };
  return (
    <div className="chat-box">
      <div className="chat-messages">
        {messages.map((m, i) => (
          <p key={i} className={`chat-message ${m.system ? "system" : ""}`}>
            {m.system ? (
              m.msg
            ) : (
              <>
                <span className="sender">{m.from}</span> @ <span className="timestamp">{formatTimestamp(m)}</span>: {m.msg}
              </>
            )}
          </p>
        ))}
        <div ref={messagesEndRef} />
      </div>
      <div className="chat-input-row">
        <input
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => e.key === "Enter" && handleSend()}
          placeholder="Type a message..."
          className="chat-input"
        />
        <button onClick={handleSend} className="send-button">Send</button>
      </div>
    </div>
  );
});
export default ChatBox;

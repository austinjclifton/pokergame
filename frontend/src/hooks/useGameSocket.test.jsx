import { act, cleanup, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import useGameSocket from "./useGameSocket";

class MockWebSocket {
  static CONNECTING = 0;
  static OPEN = 1;
  static CLOSING = 2;
  static CLOSED = 3;
  static instances = [];

  constructor(url) {
    this.url = url;
    this.readyState = MockWebSocket.CONNECTING;
    this.sent = [];
    MockWebSocket.instances.push(this);
  }

  open() {
    this.readyState = MockWebSocket.OPEN;
    this.onopen?.();
  }

  receive(message) {
    this.onmessage?.({ data: JSON.stringify(message) });
  }

  close(code = 1000, reason = "") {
    if (this.readyState === MockWebSocket.CLOSED) return;
    this.readyState = MockWebSocket.CLOSED;
    this.closeCode = code;
    this.closeReason = reason;
    this.onclose?.({ code, reason });
  }

  disconnect(code = 1006) {
    this.readyState = MockWebSocket.CLOSED;
    this.onclose?.({ code, reason: "network loss" });
  }

  send(payload) {
    this.sent.push(JSON.parse(payload));
  }
}

const publicState = (overrides = {}) => ({
  phase: "preflop",
  board: [],
  pot: 30,
  currentBet: 20,
  actionSeat: 1,
  players: {
    1: { seat: 1, user_id: 11, username: "One", stack: 990, bet: 10 },
    2: { seat: 2, user_id: 22, username: "Two", stack: 980, bet: 20 },
  },
  ...overrides,
});

async function finishConnection() {
  await act(async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
  });
  return MockWebSocket.instances.at(-1);
}

describe("useGameSocket lifecycle", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date("2026-10-23T12:00:00Z"));
    MockWebSocket.instances = [];
    vi.stubGlobal("WebSocket", MockWebSocket);
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: true,
      json: vi.fn().mockResolvedValue({ ok: true, token: "test-token" }),
    }));
    localStorage.clear();
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("connects and applies public and private authoritative state", async () => {
    const { result } = renderHook(() => useGameSocket("7", vi.fn(), vi.fn()));
    const socket = await finishConnection();

    act(() => socket.open());
    act(() => socket.receive({
      type: "STATE_SYNC",
      game_id: 70,
      version: 1,
      state: publicState(),
    }));
    act(() => socket.receive({
      type: "STATE_PRIVATE",
      seat: 1,
      state: { mySeat: 1, myCards: ["AS", "KH"], legalActions: ["call"] },
    }));

    expect(socket.url).toContain("table_id=7");
    expect(result.current.connected).toBe(true);
    expect(result.current.gameState.gameVersion).toBe(1);
    expect(result.current.gameState.pot).toBe(30);
    expect(result.current.gameState.holeCards).toEqual(["AS", "KH"]);
    expect(result.current.gameState.legalActions).toEqual(["call"]);
  });

  it("ignores duplicate and stale diffs while accepting the next version", async () => {
    const { result } = renderHook(() => useGameSocket("7", vi.fn(), vi.fn()));
    const socket = await finishConnection();
    act(() => socket.open());
    act(() => socket.receive({ type: "STATE_SYNC", version: 1, state: publicState() }));
    act(() => socket.receive({
      type: "STATE_DIFF",
      version: 2,
      state: publicState({ pot: 60, actionSeat: 2 }),
    }));
    act(() => socket.receive({
      type: "STATE_DIFF",
      version: 2,
      state: publicState({ pot: 999 }),
    }));
    act(() => socket.receive({
      type: "STATE_DIFF",
      version: 1,
      state: publicState({ pot: 1 }),
    }));
    act(() => socket.receive({
      type: "STATE_SYNC",
      version: 1,
      state: publicState({ pot: 2 }),
    }));
    act(() => socket.receive({
      type: "hand_end",
      summary: { board: ["2S"], players: {}, winners: [], reason: "fold" },
    }));
    act(() => socket.receive({
      type: "hand_start",
      version: 1,
      state: publicState({ phase: "flop", pot: 1 }),
    }));

    expect(result.current.gameState.gameVersion).toBe(2);
    expect(result.current.gameState.pot).toBe(60);
    expect(result.current.gameState.actionSeat).toBe(2);
    expect(result.current.gameState.handSummary?.reason).toBe("fold");
  });

  it("reconnects once after network loss and rehydrates after a server restart", async () => {
    const { result } = renderHook(() => useGameSocket("7", vi.fn(), vi.fn()));
    const first = await finishConnection();
    act(() => first.open());
    act(() => first.receive({ type: "STATE_SYNC", version: 3, state: publicState({ pot: 80 }) }));

    act(() => {
      first.disconnect();
      first.onclose?.({ code: 1006, reason: "duplicate close notification" });
    });
    expect(result.current.reconnecting).toBe(true);

    await act(async () => {
      await vi.advanceTimersByTimeAsync(3000);
    });
    const second = await finishConnection();
    act(() => second.open());
    act(() => second.receive({ type: "STATE_SYNC", version: 3, state: publicState({ pot: 90 }) }));

    expect(fetch).toHaveBeenCalledTimes(2);
    expect(MockWebSocket.instances).toHaveLength(2);
    expect(result.current.reconnecting).toBe(false);
    expect(result.current.gameState.gameVersion).toBe(3);
    expect(result.current.gameState.pot).toBe(90);
  });

  it("clears private state and closes the old socket when switching tables", async () => {
    const { result, rerender } = renderHook(
      ({ tableId }) => useGameSocket(tableId, vi.fn(), vi.fn()),
      { initialProps: { tableId: "7" } },
    );
    const first = await finishConnection();
    act(() => first.open());
    act(() => first.receive({
      type: "STATE_PRIVATE",
      seat: 1,
      state: { myCards: ["AS", "AH"], legalActions: ["raise"] },
    }));
    expect(result.current.gameState.holeCards).toEqual(["AS", "AH"]);

    rerender({ tableId: "8" });
    const second = await finishConnection();
    act(() => first.receive({
      type: "STATE_PRIVATE",
      seat: 1,
      state: { myCards: ["KS", "KH"], legalActions: ["call"] },
    }));

    expect(first.readyState).toBe(MockWebSocket.CLOSED);
    expect(second.url).toContain("table_id=8");
    expect(result.current.gameState.holeCards).toEqual([]);
    expect(result.current.gameState.legalActions).toEqual([]);
    expect(result.current.gameState.gameVersion).toBe(0);
  });

  it("advances hands and stores a card-free terminal summary for forfeits", async () => {
    const onMatchEnd = vi.fn();
    const { result } = renderHook(() => useGameSocket("7", vi.fn(), onMatchEnd));
    const socket = await finishConnection();
    act(() => socket.open());
    act(() => socket.receive({ type: "STATE_SYNC", version: 4, state: publicState() }));
    act(() => socket.receive({
      type: "hand_end",
      summary: { board: ["2S"], players: {}, winners: [], reason: "fold" },
    }));

    await act(async () => {
      await vi.advanceTimersByTimeAsync(5000);
    });
    expect(socket.sent).toContainEqual({ type: "next_hand", game_version: 4 });

    act(() => socket.receive({
      type: "hand_start",
      version: 5,
      state: publicState({ phase: "flop", board: ["2S", "3H", "4D"] }),
    }));
    expect(result.current.gameState.phase).toBe("FLOP");
    expect(result.current.gameState.handSummary).toBeNull();

    act(() => socket.receive({
      event: "match_end",
      reason: "forfeit",
      winner: { seat: 1, username: "One", stack: 2000 },
      loser: { seat: 2, username: "Two", stack: 0 },
      players: {
        1: { seat: 1, cards: ["AS", "AH"] },
        2: { seat: 2, cards: ["KS", "KH"] },
      },
    }));

    const stored = JSON.parse(localStorage.getItem("matchSummary_7"));
    expect(stored.finalHand.board).toEqual([]);
    expect(stored.finalHand.players).toEqual({});
    expect(onMatchEnd).toHaveBeenCalledWith("7");
  });

  it("closes the socket and cancels pending hand timers after unmount", async () => {
    const { unmount } = renderHook(() => useGameSocket("7", vi.fn(), vi.fn()));
    const socket = await finishConnection();
    act(() => socket.open());
    act(() => socket.receive({
      type: "hand_end",
      summary: { board: [], players: {}, winners: [], reason: "fold" },
    }));

    unmount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(6000);
    });

    expect(socket.readyState).toBe(MockWebSocket.CLOSED);
    expect(socket.sent).not.toContainEqual(expect.objectContaining({ type: "next_hand" }));
    expect(MockWebSocket.instances).toHaveLength(1);
    expect(vi.getTimerCount()).toBe(0);
  });
});

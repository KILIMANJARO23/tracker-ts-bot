import React from "react";
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
} from "recharts";

declare global {
  interface Window {
    Telegram?: any;
  }
}

// Базовый URL API бота, который доступен снаружи через ngrok (порт 3000)
const API_BASE = "https://unbarbarously-pillowlike-travis.ngrok-free.dev";

type ApiDashboard = {
  ok: boolean;
  telegramUserId: number | string;
  stats: {
    habitsCount: number;
    goalsCount: number;
    transactionsCount: number;
  };
  habits: { id: number; title: string; days: string; notify: boolean }[];
  habitsByWeekday: { day: string; value: number }[];
};

export function App() {
  const [token, setToken] = React.useState<string | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [dashboard, setDashboard] = React.useState<ApiDashboard | null>(null);

  async function auth() {
    setError(null);
    const initData = window.Telegram?.WebApp?.initData as string | undefined;
    if (!initData) {
      setError("Нет Telegram.WebApp.initData. Открой Mini App внутри Telegram.");
      return;
    }

    const res = await fetch(`${API_BASE}/api/auth/telegram`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ initData }),
    });
    const json = await res.json();
    if (!json.ok) {
      setError(json.error ?? "auth error");
      return;
    }
    setToken(json.token);
  }

  async function loadDashboard() {
    if (!token) return;
    const res = await fetch(`${API_BASE}/api/dashboard`, {
      headers: { Authorization: `Bearer ${token}` },
    });
    setDashboard(await res.json());
  }

  const isAuthed = Boolean(token);

  return (
    <div
      style={{
        fontFamily: "system-ui, -apple-system, Segoe UI, Roboto, Arial",
        padding: 16,
        maxWidth: 960,
        margin: "0 auto",
      }}
    >
      <h2 style={{ marginTop: 0 }}>Трекер — Mini App</h2>

      <p style={{ opacity: 0.8, marginBottom: 16 }}>
        Визуализация привычек и целей. Сейчас графики строятся по данным из нового TS‑сервера.
      </p>

      <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 12 }}>
        <button onClick={auth}>Войти через Telegram</button>
        <button onClick={loadDashboard} disabled={!token}>
          Обновить данные
        </button>
      </div>

      {error && (
        <pre style={{ color: "crimson", whiteSpace: "pre-wrap", marginBottom: 12 }}>{error}</pre>
      )}

      {isAuthed && dashboard && (
        <>
          <section
            style={{
              display: "grid",
              gridTemplateColumns: "repeat(auto-fit, minmax(140px, 1fr))",
              gap: 12,
              marginBottom: 20,
            }}
          >
            <StatCard label="Привычки" value={dashboard.stats.habitsCount} />
            <StatCard label="Цели" value={dashboard.stats.goalsCount} />
            <StatCard label="Транзакции" value={dashboard.stats.transactionsCount} />
          </section>

          <section style={{ marginBottom: 24 }}>
            <h3 style={{ margin: "0 0 8px" }}>Нагрузка по дням недели</h3>
            <p style={{ opacity: 0.7, margin: "0 0 8px" }}>
              Сколько привычек запланировано на каждый день недели.
            </p>
            <div style={{ width: "100%", height: 240, background: "#f6f6f6", borderRadius: 8, padding: 8 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={dashboard.habitsByWeekday}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} />
                  <XAxis dataKey="day" />
                  <YAxis allowDecimals={false} />
                  <Tooltip />
                  <Bar dataKey="value" fill="#4f46e5" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </section>

          <section>
            <h3 style={{ margin: "0 0 8px" }}>Список привычек</h3>
            {dashboard.habits.length === 0 ? (
              <p style={{ opacity: 0.7 }}>Пока нет ни одной привычки.</p>
            ) : (
              <ul style={{ listStyle: "none", padding: 0, margin: 0, display: "grid", gap: 8 }}>
                {dashboard.habits.map((h) => (
                  <li
                    key={h.id}
                    style={{
                      borderRadius: 8,
                      padding: 8,
                      background: "#f9fafb",
                      border: "1px solid #e5e7eb",
                    }}
                  >
                    <div style={{ fontWeight: 600, marginBottom: 4 }}>{h.title}</div>
                    <div style={{ fontSize: 12, opacity: 0.8 }}>
                      Дни: {h.days || "—"} • Уведомления: {h.notify ? "🔔 вкл" : "🔕 выкл"}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      )}

      {!isAuthed && (
        <p style={{ opacity: 0.7, marginTop: 16 }}>
          Чтобы увидеть свои данные, сначала нажми “Войти через Telegram” внутри Mini App.
        </p>
      )}
    </div>
  );
}

type StatCardProps = { label: string; value: number };

function StatCard({ label, value }: StatCardProps) {
  return (
    <div
      style={{
        borderRadius: 8,
        padding: 12,
        background: "#f9fafb",
        border: "1px solid #e5e7eb",
      }}
    >
      <div style={{ fontSize: 12, textTransform: "uppercase", opacity: 0.7, marginBottom: 4 }}>
        {label}
      </div>
      <div style={{ fontSize: 20, fontWeight: 600 }}>{value}</div>
    </div>
  );
}


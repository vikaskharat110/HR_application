import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Next.js blocks dev-server requests from any origin other than localhost
  // by default (a Server Actions CSRF protection) — without this, opening
  // the app from a phone at http://<lan-ip>:3000 silently fails to hydrate,
  // so every client component (the mobile nav button included) never
  // becomes interactive. Wildcarded to the whole private LAN range since a
  // router can hand out a different address on each reconnect.
  allowedDevOrigins: ["192.168.0.*", "192.168.1.*", "10.0.0.*"],
  experimental: {
    // Next's Server Actions default to a 1MB request body limit — too small
    // for document uploads (offer letters, ID proofs, contracts). Raised to
    // cover a 10MB file plus multipart/form-data overhead.
    serverActions: {
      bodySizeLimit: "11mb",
    },
  },
};

export default nextConfig;

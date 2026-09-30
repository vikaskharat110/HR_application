"use client";

import { useState, useTransition } from "react";
import { checkIn, checkOut } from "@/lib/actions/attendance";
import { formatTime } from "@/lib/format";
import { distanceMeters } from "@/lib/geo";
import { LogIn, LogOut, Clock, Loader2, AlertCircle, MapPin } from "lucide-react";

function getLocation(): Promise<GeolocationPosition | null> {
  return new Promise((resolve) => {
    if (!("geolocation" in navigator)) {
      resolve(null);
      return;
    }
    navigator.geolocation.getCurrentPosition(
      (pos) => resolve(pos),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  });
}

function locationErrorMessage(): string {
  if (!("geolocation" in navigator)) {
    return "Your browser doesn't support location — check-in may be blocked if your branch requires it.";
  }
  return "Couldn't get your location. Make sure location access is allowed for this site and location services are turned on, then try again.";
}

function LocationNote({
  lat,
  lng,
  branch,
}: {
  lat: number | null;
  lng: number | null;
  branch: { name: string; latitude: number | null; longitude: number | null } | null;
}) {
  if (lat == null || lng == null) return null;

  const distance = branch?.latitude != null && branch.longitude != null ? Math.round(distanceMeters(lat, lng, branch.latitude, branch.longitude)) : null;

  return (
    <a
      href={`https://www.google.com/maps?q=${lat},${lng}`}
      target="_blank"
      rel="noreferrer"
      className="flex items-center gap-1 text-[11px] text-white/50 hover:text-white/80 mt-0.5"
      title="View on map"
    >
      <MapPin size={10} />
      {distance != null ? `~${distance}m from ${branch!.name}` : "View location"}
    </a>
  );
}

export function CheckInOutCard({
  checkInAt,
  checkOutAt,
  checkInLat = null,
  checkInLng = null,
  checkOutLat = null,
  checkOutLng = null,
  branch = null,
  geofenceEnabled,
}: {
  checkInAt: Date | string | null;
  checkOutAt: Date | string | null;
  checkInLat?: number | null;
  checkInLng?: number | null;
  checkOutLat?: number | null;
  checkOutLng?: number | null;
  branch?: { name: string; latitude: number | null; longitude: number | null } | null;
  /** Company-wide toggle (Settings → Policy). Off means never prompt for location at all — there's no point asking when the server won't check it either way. */
  geofenceEnabled: boolean;
}) {
  const [pending, startTransition] = useTransition();
  const [locating, setLocating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function run(action: (lat?: number, lng?: number) => Promise<{ error?: string } | undefined>) {
    setError(null);

    const proceed = (pos: GeolocationPosition | null) => {
      startTransition(async () => {
        const result = pos
          ? await action(pos.coords.latitude, pos.coords.longitude)
          : await action();
        // The server only returns an error when it actually needed the
        // location (geofencing is on company-wide AND this branch has one
        // configured) and either didn't get it or it fell outside the
        // radius — so this message only ever shows up when it's relevant.
        if (result?.error) {
          setError(!pos ? `${locationErrorMessage()} ${result.error}` : result.error);
        }
      });
    };

    if (!geofenceEnabled) {
      proceed(null);
      return;
    }
    setLocating(true);
    getLocation().then((pos) => {
      setLocating(false);
      proceed(pos);
    });
  }

  const busy = pending || locating;

  return (
    <div className="card p-5 bg-brand-dark text-white border-0">
      <div className="flex items-center gap-2 text-white/70 text-xs font-semibold uppercase tracking-wide mb-3">
        <Clock size={14} /> Today
      </div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <p className="text-xs text-white/50">Check-in</p>
          <p className="font-display text-lg font-semibold">{checkInAt ? formatTime(checkInAt) : "—"}</p>
          <LocationNote lat={checkInLat} lng={checkInLng} branch={branch} />
        </div>
        <div className="w-px h-8 bg-white/15" />
        <div>
          <p className="text-xs text-white/50">Check-out</p>
          <p className="font-display text-lg font-semibold">{checkOutAt ? formatTime(checkOutAt) : "—"}</p>
          <LocationNote lat={checkOutLat} lng={checkOutLng} branch={branch} />
        </div>
      </div>

      {!checkInAt && (
        <button
          disabled={busy}
          onClick={() => run(checkIn)}
          className="btn w-full justify-center bg-[var(--color-accent)] text-white hover:bg-[var(--color-accent-dark)] disabled:opacity-70"
        >
          {busy ? <Loader2 size={15} className="animate-spin" /> : <LogIn size={15} />}
          {locating ? "Getting location…" : "Check in"}
        </button>
      )}
      {checkInAt && !checkOutAt && (
        <button
          disabled={busy}
          onClick={() => run(checkOut)}
          className="btn w-full justify-center bg-white text-[var(--color-brand-dark)] hover:bg-white/90 disabled:opacity-70"
        >
          {busy ? <Loader2 size={15} className="animate-spin" /> : <LogOut size={15} />}
          {locating ? "Getting location…" : "Check out"}
        </button>
      )}
      {checkInAt && checkOutAt && (
        <p className="text-sm text-white/70 text-center py-1.5">You&rsquo;re done for today. See you tomorrow!</p>
      )}

      {error && (
        <div className="flex items-start gap-2 text-xs text-white bg-white/10 rounded-lg px-3 py-2.5 mt-3">
          <AlertCircle size={14} className="mt-0.5 shrink-0" /> <span>{error}</span>
        </div>
      )}
      {!error && !checkInAt && geofenceEnabled && (
        <p className="flex items-center gap-1.5 text-[11px] text-white/40 mt-2.5">
          <MapPin size={11} /> Your location may be checked against your branch on check-in.
        </p>
      )}
    </div>
  );
}

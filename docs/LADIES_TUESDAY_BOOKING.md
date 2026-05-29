# Ladies Tuesday — Event-Driven Booking

_Booking Engine version: 1.10.0 · Last updated: 2026-05-29_

## What changed

Previously the Ladies Tuesday reservation form rendered the **same open
calendar as the standard lane booking** — every date for the next 90 days was
selectable, with generic business-hours time slots. That let visitors "book" a
Ladies Tuesday lane on any day, which is wrong.

Now the Ladies Tuesday form is **event-driven**: the calendar only unlocks dates
that have a published **Event**, and the time slots come straight from that
event's window. Non-event dates are greyed out; event dates get a brass ring +
dot.

## How it works

- The page template renders `[g2a_ladies_tuesday_booking]`, which defaults to
  `source="events" event_type="ladies-day"`.
- On load, the calendar calls `GET /wp-json/g2ab/v1/event-availability` and
  receives the list of bookable dates + per-date slots for the `ladies-day`
  event type.
- Only those dates are clickable; slots are the event's start→end window
  (stepped by the booking type's duration), with live capacity/seat checks.
- On submit, the server **re-validates** that the chosen slot falls inside a
  published event window (it no longer requires the slot to sit inside generic
  business hours for event-gated types). Blackouts still apply.

## What you (admin) need to do

To make dates appear in the Ladies Tuesday calendar, publish Events:

1. **G2A Booking → Events → Add Event.**
2. Set:
   - **Event date** — the Tuesday (or any date) you want bookable.
   - **Start time** / **End time** — e.g. `10:00 AM` → `6:00 PM`. The booking
     slots are generated across this window.
   - **Event type** — must be `ladies-day` (this is what the form filters on).
   - **Total seats** — optional; caps capacity for that date (otherwise the
     resource capacity is used).
   - **Booking type slug** — `ladies-tuesday` (informational/CTA linking).
3. **Publish.** The date now appears highlighted in the calendar; past dates and
   dates with no live slots are automatically hidden.

Make sure a **`ladies-tuesday` booking type** and at least one matching
**resource** (lane) exist under **G2A Booking → Booking Types / Resources**.

## Configurable / reusable

This is generic — any booking type can be event-gated, two ways:

1. **Shortcode:** `[g2a_lane_booking booking_type="my-type" source="events" event_type="my-event-type"]`
2. **Booking-type settings:** set `event_source = events` and
   `event_type = <slug>` in the booking type's settings JSON. The bundled
   `ladies-tuesday` type is event-gated by convention even without this.

To revert Ladies Tuesday to the open calendar, render
`[g2a_ladies_tuesday_booking source=""]`.

## REST reference

`GET /wp-json/g2ab/v1/event-availability`

| param | required | notes |
|---|---|---|
| `resource_id` | yes | the lane/resource being booked |
| `event_type` | no | event type slug filter (e.g. `ladies-day`) |
| `booking_type_id` | no | applies the type's duration/buffers/capacity mode |
| `party_size` | no | for party-size capacity types |

Response:

```json
{
  "success": true,
  "data": {
    "dates": ["2026-06-02", "2026-06-09"],
    "by_date": {
      "2026-06-02": {
        "title": "Ladies Tuesday",
        "slots": [
          { "start": "2026-06-02 10:00:00", "end": "2026-06-02 11:00:00",
            "label": "10:00 AM", "available": true, "seats_left": 6 }
        ]
      }
    }
  }
}
```

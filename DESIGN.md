---
version: alpha
name: The Job Card
description: Dot.Tasks. Work as cards in a rack, moved by hand, in ink on stone with one green that means done.
colors:
  primary: "#171815"
  on-primary: "#FFFFFF"
  secondary: "#5E605A"
  tertiary: "#1C7C54"
  on-tertiary: "#FFFFFF"
  neutral: "#F3F3F0"
  surface: "#FFFFFF"
  raised: "#ECECE7"
  active: "#E4E4DE"
  border: "#E2E2DC"
  rule: "#EEEEE9"
  control: "#CFCFC8"
  negative: "#BE2A23"
  negative-wash: "#FBEBE9"
  waiting: "#8A5A00"
  waiting-wash: "#FBF3E0"
  done-wash: "#E6F3EC"
typography:
  title:
    fontFamily: Schibsted Grotesk
    fontSize: 24px
    fontWeight: 600
    lineHeight: 32px
    letterSpacing: -0.01em
  section:
    fontFamily: Schibsted Grotesk
    fontSize: 16px
    fontWeight: 600
    lineHeight: 24px
  body:
    fontFamily: Schibsted Grotesk
    fontSize: 14px
    fontWeight: 400
    lineHeight: 20px
  small:
    fontFamily: Schibsted Grotesk
    fontSize: 12px
    fontWeight: 400
    lineHeight: 16px
rounded:
  card: 6px
  control: 6px
  chip: 4px
spacing:
  gap: 12px
  pad: 24px
  row: 44px
  control: 34px
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.control}"
    height: 34px
  job-card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.primary}"
    rounded: "{rounded.card}"
    padding: 12px
---

## Overview

Dot.Tasks is where work happens, and work in the places it serves has always been something you move with your hand. In a workshop or on a mine, the planning board is a T-card rack: job cards slotted into columns, one card per job, the head of each showing, slid along as the job moves. The Job Card takes that board and keeps what made it good: you can see everything at once, the card is the unit, and moving one is a physical act.

So a task is a card with weight. It lifts when you pick it up, the rack opens a slot where it will land, and it seats when you let go. Nothing is decorated. The product's colour comes from the state of the work.

```
Idea:        A task is a job card, and the board is the rack it slides in.
Signature:   The week rack. Seven slots at the top of My Day, each showing the edges of the cards
             in it, so the shape of the week is seen before a word is read. Drag a card onto a
             day to move it there.
Face:        A stone ground, not white or grey, and one green used only for done and today.
Motion verb: Seat. Cards lift, slide and seat, with a 120 ms settle. Nothing fades in for show.
```

Applied from the taste skill's app chapter, style `achromatic-ledger` (seed 247617756). One redraw: its icon rail is replaced by a labelled sidebar, because boards are named destinations people switch between all day. Geist is replaced by Schibsted Grotesk, which the landing page already uses, so the product and its site share one face.

## Colors

- **Stone (#F3F3F0)** is the ground, the colour of a rack behind white cards.
- **Ink (#171815)** is text, the primary button and the marks on a card.
- **Green (#1C7C54)** means done or today, and nothing else. It is the one hue on data.
- **Red (#BE2A23)** means overdue, once per card. Amber ink (#8A5A00) means blocked or waiting.

The brand's gold and green live in the logo only. Chrome stays ink.

## Typography

One face, Schibsted Grotesk, at 24, 16, 14 and 12. Figures are tabular on bare numbers only (counts, totals): in this face the tabular feature also widens commas and full stops, so it never goes on running text. Weight 600 for titles, 500 for a card's title, 400 for the rest. Sentence case, always.

## Layout

A labelled 232px sidebar on the stone, with boards listed under the views. A 52px top bar carries search and quick add. The canvas is fluid with a 24px gutter. One gap value, 12px, inside a board and a list. Pages open on a noun title at 24px with one line under it that states a fact about the work.

## Elevation & Depth

Nothing has a shadow at rest. Cards sit on the rack by contrast with it, with a 1px edge. A card being dragged lifts with one real shadow, because it is off the rack. Menus, the task panel and dialogs float the same way.

## Shapes

Tight corners: 6px on cards and controls, 4px on chips. The tick is the one round thing, taken from the dot in the logo.

## Components

- **Job card:** title at 14/500, then one line of meta: due date, subtask count, person. Priority is a notch on the card's left edge: heavier for higher, none for normal. No pills for it.
- **The tick:** a 18px ring. Finishing fills it from the centre in green, and the card seats out.
- **Column:** a tone step on the stone, with no border, so cards read as sitting in it.
- **Row:** 44px, with a rule one step lighter than a card edge. The grip shows on hover and always on touch.
- **Slot:** while dragging, a 2px ink line opens where the card will land.
- **Task panel:** a 520px sheet from the right, always the same panel from every view.
- **Week rack:** seven equal slots, today in green, card edges 3px thick, overdue edges red.

## Do's and Don'ts

- Do let the work carry the colour. If the screen is grey, nothing needs attention.
- Do keep every action reachable by keyboard. Dragging is one way to move a card, never the only way.
- Do say what happened in plain words when something can't be done.
- Don't colour priority. A red urgent pill on every card is noise.
- Don't show a count tile that nobody acts on.
- Don't animate anything that doesn't report a change. No entrances, no pulsing dots.
- Don't draw a real rack: no holes, no metal, no skeuomorphism. The idea is in how it moves.

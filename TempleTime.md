# Temple Time
## Temple Worship Planning, Tracking, and Memory Application

## 1. Application Vision

**Temple Time** is a personal temple worship planning, tracking, and memory application.

The application is designed to help a user:

- Maintain a personal list of temples.
- Record temple visits and meaningful experiences.
- Track who shared those experiences.
- Plan future visits.
- Add planned visits to Google Calendar.
- Navigate to temples using Google Maps.
- Preserve photos and journal-style memories.
- Review meaningful statistics and visit patterns.
- Maintain a personal **My Visit List** of temples intentionally selected for future visits.

Temple Time should feel like a combination of:

- Personal temple journal
- Visit planner
- Photo memory collection
- Temple history
- Lightweight statistics dashboard

The application should emphasize memories, worship, consistency, and personal history rather than competition or performance.

---

## 2. Design Principles

### 2.1 Keep Routine Entry Simple

Routine temple visits should be fast to record.

A **Quick Log** should allow a user to record a visit using only:

- Temple
- Date
- Who With
- Group
- Visit Purpose
- Work Performed

The user may then choose **Add More Details** to add journal entries, photos, tags, and other information.

---

### 2.2 A Visit Does Not Require Temple Work

A temple Visit represents being at a temple or temple grounds.

Valid Visits may include:

- Temple Work
- Temple Grounds
- Open House
- Tour
- Family Visit
- Youth / Children Visit
- Prospective Temple-Goer Visit
- Special Event
- Other meaningful temple experiences

This allows Temple Time to preserve experiences such as taking children or prospective temple-goers to temple grounds even when no ordinance work is performed.

---

### 2.3 Plans and Visits Are Different

A **Plan** describes what the user intends to do.

A **Visit** records what actually happened.

A Plan can later be converted into a Visit so information does not need to be entered twice.

---

### 2.4 Use Clickable Visual Cards

Temple Time should use consistent clickable cards throughout the application for:

- Temples
- Visits
- Plans
- People
- Photos

Cards should use optimized thumbnail images and only the most useful summary information.

Detailed information belongs on the corresponding detail page.

---

### 2.5 Preserve Memories Without Overloading the User

The application should support rich journal content but never require it.

Fields such as:

- Spiritual Impressions
- Memorable Experiences
- People Encountered
- Photo Captions
- Tags

should always be optional.

---

### 2.6 Statistics Should Be Reflective

Statistics should help the user understand and revisit their temple journey.

They should not imply that larger numbers make one experience more meaningful than another.

---

## 3. Core Application Areas

The initial version of Temple Time includes:

1. Dashboard
2. Temples
3. Visits
4. People
5. Plans
6. Photo Gallery
7. Statistics

Future phases may add more extensive trip planning and Temple Memories Book generation.

---

# 4. Core Data Relationships

The primary relationships are:

```text
Temple
  ├── Visits
  ├── Plans
  └── Photos

Visit
  ├── Temple
  ├── People
  ├── Group
  ├── Visit Purposes
  ├── Work Performed
  ├── Tags
  └── Photos

Plan
  ├── Temple
  ├── People
  ├── Group
  ├── Planned Purposes
  └── Planned Work

Person
  ├── Visits
  ├── Plans
  └── Photos
```

A Temple is the place.

A Visit records an actual experience.

A Plan records a future intention.

A Person represents someone who shared one or more temple experiences.

---

# 5. Data Model

## 5.1 Temple Record

A **Temple** represents a physical temple location.

A Temple should normally be created once and reused by Visits and Plans.

### Basic Information

- Temple ID
  - System-generated unique identifier.

- Temple Name
  - Required.

- Short Name
  - Optional.

- Status
  - Suggested values:
    - Operating
    - Announced
    - Under Construction
    - Open House
    - Dedicated
    - Renovation / Closed
    - Other

- Primary Photo
  - Optional.

### Location Information

- Street Address
- Address Line 2
- City
- State / Province / Region
- Postal Code
- Country
- Latitude
- Longitude

Latitude and longitude may be used for:

- Nearby temple searches
- Distance calculations
- Map displays
- Google Maps navigation

### Contact and Reference Information

- Phone Number
- Temple Website
- General Temple Notes

Temple Notes are for information about the place itself, such as:

- Parking information
- Entrance information
- Accessibility notes
- Travel reminders

They are not used for Visit memories.

### Personal Temple Status

- Favorite
- On My Visit List

**My Visit List** represents temples the user has deliberately chosen as temples they intend to visit someday.

User-facing actions:

- Add to My Visit List
- On My Visit List
- Remove from My Visit List

The following values should be calculated from Visit records:

- Visited
- First Visit Date
- Most Recent Visit Date
- Number of Visits
- Temple Grounds Visited

### Temple Photos

A Temple may have multiple associated Photos.

One Photo may be selected as the Temple Primary Photo.

---

## 5.2 Visit Record

A **Visit** represents an actual occasion when the user went to a temple or temple grounds.

Each Visit is associated with one Temple.

### Core Visit Information

- Visit ID
- Temple
  - Required.
- Visit Date
  - Required.
- Arrival Time
  - Optional.
- Departure Time
  - Optional.
- Duration
  - Optional or calculated.

Future dates belong in Plans rather than Visits.

### Visit Purpose

A Visit may have one or more Purposes.

Suggested options:

- Temple Work
- Temple Grounds
- Open House
- Tour
- Family Visit
- Youth / Children Visit
- Prospective Temple-Goer Visit
- Special Event
- Other

Purposes should use a multi-select or checkbox interface.

### Work Performed

If temple work applies, the user may select one or more:

- Baptisms
- Confirmations
- Initiatory
- Endowment
- Sealing
- Other

Selections accumulate.

Examples:

- E
- IE
- BIE
- ES

Shorthand is only for compact display. Each work type must remain stored separately.

### Who With

A Visit may include zero, one, or many People selected from the People table.

### Group

A Visit may also contain an optional free-text Group value.

Examples:

- Ward Youth
- Relief Society
- Family Group
- Friends

Details about the group may be entered in Visit Notes.

### Notes

General Visit information.

Examples:

- Went to dinner afterward.
- Parking was crowded.
- Arrived early.

### Spiritual Impressions

Optional journal area for spiritual thoughts or impressions.

### Memorable Experiences

Optional area for experiences the user especially wants to remember.

### People Encountered

Optional area for people met or seen during the Visit.

This may include:

- Linked People records
- Free-text names or descriptions

### Tags

Visits may contain multiple user-created Tags.

Examples:

- Family
- Grandchildren
- Anniversary
- Vacation
- First Visit
- Christmas
- Youth

Tags may later support:

- Search
- Filtering
- Photo browsing
- Statistics
- Memories Book creation

### Favorite Visit

A Visit may optionally be marked as a Favorite Visit.

### Visit Photos

A Visit may contain multiple Photos.

One Photo may optionally be selected as the Visit Cover Photo.

---

## 5.3 People Record

A **Person** represents someone associated with Temple Visits, Plans, Photos, or memories.

The People area should remain lightweight and should not become a full contact-management system.

### Person Fields

- Person ID
- First Name
  - Required.
- Last Name
  - Optional.
- Preferred / Display Name
  - Optional.
- Relationship
  - Optional.
- Profile Photo
  - Optional.
- Notes
  - Optional.
- Active
  - Yes / No.

Suggested Relationship values:

- Spouse
- Child
- Grandchild
- Parent
- Sibling
- Other Family
- Friend
- Church Friend
- Ward Member
- Other

An inactive Person remains connected to historical data.

### People Statistics

A Person Detail page may calculate:

- Total Visits Together
- Different Temples Visited Together
- First Visit Together
- Most Recent Visit Together
- Photos Together

---

## 5.4 Plan Record

A **Plan** represents an intended future temple visit.

### Core Plan Information

- Plan ID
- Temple
  - Required.
- Planned Date
  - Required.
- Planned Time
  - Optional.
- End Time
  - Optional.

### Planned Visit Purpose

Optional multi-select using the same Purpose values as Visits.

### Planned Work

Optional multi-select using the same Work Performed values as Visits.

### Who With

Zero, one, or many People from the People table.

### Group

Optional free-text Group value.

### Plan Notes

Optional notes for logistics or preparation.

Examples:

- Meet at our house at 8:30.
- Dinner afterward.
- Take the grandchildren around the grounds.

Plan Notes should remain with the Plan and should not automatically become Visit Notes.

### Plan Status

Suggested initial values:

- Planned
- Completed
- Cancelled

### Convert Plan to Visit

A Plan should provide a **Log This Visit** action.

The resulting Visit may carry forward:

- Temple
- Planned Date
- People
- Group
- Visit Purpose
- Planned Work

The user then confirms what actually occurred and may add Visit details.

After saving:

- The Visit is linked to the Plan.
- Plan Status becomes Completed.

Completed Plans should remain in history.

---

## 5.5 Photo Record

Each Photo should have its own record.

Suggested fields:

- Photo ID
- Image File
- Thumbnail File
- Original Filename
- Date Taken
- Caption
- Favorite Photo
- Temple
- Visit
- People
- Tags
- Uploaded Date

A Photo should be stored once and may appear in several related areas.

---

# 6. Dashboard

The Dashboard is the main home screen.

It should combine:

- Looking Back
- Looking Now
- Looking Ahead

## 6.1 Primary Quick Actions

Suggested prominent actions:

- Quick Log
- Plan a Visit
- Add Temple
- View My Visit List
- Nearby Temples
- Photo Gallery

## 6.2 Coming Up

Display the nearest upcoming Plan.

Suggested content:

```text
Jordan River Utah Temple
Saturday, October 17
10:00 AM
Endowment
With Elaine

[Navigate] [Calendar] [Log Visit]
```

Provide **View All Plans** when multiple Plans exist.

## 6.3 Recent Visits

Display the last 10 Visits as clickable visual cards.

Each card may contain:

- Visit Cover Photo or Temple Primary Photo
- Temple Name
- Visit Date
- Who With
- Visit Purpose
- Work shorthand
- Favorite indicator

Selecting a card opens the Visit Detail page.

## 6.4 Summary Statistics

Suggested Dashboard cards:

- Total Visits
- Different Temples Visited
- Visits This Year
- Current Weekly Streak

Cards should be clickable where practical.

## 6.5 Visit Streak

The default streak should be based on consecutive weeks containing at least one legitimate Temple Visit.

The general streak may include:

- Temple Work
- Temple Grounds
- Open House
- Tour
- Family Visit
- Youth / Children Visit
- Prospective Temple-Goer Visit
- Special Event
- Other valid Visits

A separate Temple Work Streak may exist on the Statistics page.

## 6.6 Recent Memories

Display a small group of recent or Favorite Photos.

Selecting a Photo should open the Photo Detail view or associated Visit.

## 6.7 My Visit List

Show a small Dashboard summary of temples on My Visit List.

## 6.8 Empty Dashboard

For a new user:

```text
Welcome to Temple Time

Begin building your temple journey.

[Add Your First Temple]
[Log Your First Visit]
```

---

# 7. Temples

## 7.1 Temple List

The Temple List should use clickable visual cards.

Each card may show:

- Temple Primary Photo
- Temple Name
- City / State / Region
- Visited indicator
- Number of Visits
- Most Recent Visit
- Favorite indicator
- My Visit List indicator
- Upcoming Plan indicator

## 7.2 Temple Views

Suggested views:

- All Temples
- Visited
- Not Yet Visited
- My Visit List
- Favorites
- Nearby
- Recently Visited

## 7.3 Search

Search by:

- Temple Name
- City
- State / Province / Region
- Country

## 7.4 Sorting

Suggested sort options:

- Temple Name
- Distance
- Most Recently Visited
- Most Frequently Visited
- City
- State / Region
- Country

## 7.5 Nearby Temples

Nearby mode may support distance filters such as:

- Within 25 miles
- Within 50 miles
- Within 100 miles
- Within 200 miles
- Custom distance

The user should be able to choose a reference location such as:

- Current Location
- Home
- Another City

Nearby cards may display:

- Distance
- Approximate driving time when available
- Visited status
- My Visit List status

---

## 7.6 Temple Detail Page

The Temple Detail page should combine:

- Temple information
- Visit history
- Plans
- Photos
- Statistics
- Actions

### Header

Display:

- Large Primary Photo
- Temple Name
- City / State / Country
- Full Address
- Current Status
- Favorite
- On My Visit List
- Visited
- Upcoming Plan

### Primary Actions

- Plan a Visit
- Quick Log
- Log Visit
- Navigate
- Add Photo
- Add to My Visit List
- Favorite

### Temple Statistics

Suggested items:

- Total Visits
- First Visit
- Most Recent Visit
- Ordinance Visits
- Grounds Visits
- Open Houses / Tours
- Number of People Visited With

### Recent Visits

Display the most recent Visits as clickable cards.

### Upcoming Plans

Display future Plans for this Temple.

### Temple Gallery

Display Photos associated with this Temple and its Visits.

### Suggested Tabs

- Overview
- Visits
- Plans
- Photos

---

# 8. Visits

## 8.1 Visit History

Default Visit History sorting:

**Newest First**

Visits should display as clickable visual cards.

Each card may include:

- Visit Cover Photo or Temple Primary Photo
- Temple Name
- Visit Date
- City / State
- Who With
- Group
- Visit Purpose
- Work shorthand
- Favorite indicator

## 8.2 Quick Views

Suggested views:

- All Visits
- This Year
- Favorites
- Temple Work
- Temple Grounds
- First-Time Temples

## 8.3 Search

Search may include:

- Temple Name
- City
- State / Region
- Person Name
- Group
- Notes
- Memorable Experiences
- Tags

Spiritual Impressions may be excluded from general search unless intentionally enabled.

## 8.4 Filters

Filter by:

- Date
- Temple
- Visit Purpose
- Work Performed
- Person
- Group
- Tag
- Favorite Visit

Multiple filters may be combined.

Example:

> Visits in 2027 with Elaine that included Endowment work.

---

## 8.5 Visit Detail Page

The Visit Detail page should feel like a personal memory page.

### Header

Display:

- Visit Cover Photo or Temple Primary Photo
- Temple Name
- Visit Date
- Location
- Favorite Visit indicator

### Visit Summary

Display only fields containing data:

- Who With
- Group
- Visit Purpose
- Work Performed
- Arrival
- Departure
- Duration

### Journal Sections

- Notes
- Spiritual Impressions
- Memorable Experiences
- People Encountered
- Tags

### Photos

Display Visit Photos as clickable thumbnails.

### Related Records

Provide easy access to:

- Temple
- Original Plan, when applicable

### Actions

- Edit Visit
- Add Photo
- Add Journal Details
- Navigate to Temple
- View Temple
- Duplicate as New Visit
- Delete Visit

### Duplicate as New Visit

Carry forward:

- Temple
- Who With
- Group
- Common Visit Purpose

Do not automatically carry forward:

- Date
- Notes
- Spiritual Impressions
- Memorable Experiences
- People Encountered
- Photos

---

# 9. Photo Gallery and Photo Management

## 9.1 Image Optimization

Temple Time should automatically optimize uploaded Photos.

### Standard Stored Image

Maintain the original aspect ratio.

Maximum bounding size:

**1600 × 1600 pixels**

Examples:

```text
Original: 4032 × 3024
Stored:   1600 × 1200

Original: 3024 × 4032
Stored:   1200 × 1600

Original: 1000 × 750
Stored:   1000 × 750
```

Smaller images should not be enlarged.

### Thumbnail

Create a thumbnail approximately:

**400–500 pixels on the longest side**

Use thumbnails for:

- Dashboard cards
- Temple cards
- Visit cards
- People pages
- Gallery grids

### Compression

Compress images to a sensible web-friendly quality.

The first version does not need to retain the original full-resolution upload.

A future option may retain higher-resolution images for selected Photos intended for premium printing.

---

## 9.2 Main Photo Gallery

Default display:

- Newest Photos first
- Visual thumbnail grid

Suggested filters:

- All Photos
- Favorites
- Temple
- Visit
- Person
- Tag
- Year

Multiple filters may be combined.

## 9.3 Photo Detail

Display:

- Larger optimized image
- Caption
- Date Taken
- Temple
- Visit
- People
- Tags
- Favorite indicator

Suggested actions:

- Edit Caption
- Add / Remove People
- Add / Remove Tags
- Mark Favorite
- Set as Visit Cover
- Set as Temple Primary Photo
- Delete Photo

## 9.4 Multiple Photo Upload

Allow several Photos to be uploaded at once.

When Photos are uploaded from a Visit, automatically associate them with:

- That Visit
- That Visit's Temple

Individual captions, People, and Tags may be edited afterward.

## 9.5 Storage Management

Avoid unnecessary duplication.

Each Photo should normally have:

- One standard optimized image
- One thumbnail

The same Photo may appear in multiple areas without creating duplicate image files.

---

# 10. People

## 10.1 People List

The People section should display a searchable list.

Suggested information:

- Profile Photo
- Name
- Relationship
- Number of Visits Together
- Number of Temples Visited Together

Suggested filters:

- All
- Family
- Friends
- Church
- Active
- Inactive

## 10.2 Person Detail

Display:

- Profile Photo
- Name
- Relationship
- Notes

Calculated statistics may include:

- Total Visits Together
- Different Temples Visited Together
- First Visit Together
- Most Recent Visit Together

### Recent Visits

Display recent shared Visits as clickable cards.

### Shared Temple History

Show temples visited together.

### Photos

Display Photos associated with the Person.

---

# 11. Plans

## 11.1 Plan List

The Planning section should show upcoming Plans in chronological order.

Suggested card:

```text
Jordan River Utah Temple
Saturday, October 17
10:00 AM
Endowment
With Elaine

[Navigate] [Calendar] [Log Visit]
```

Suggested views:

- Upcoming
- This Week
- This Month
- All Plans
- Completed
- Cancelled

Default:

**Upcoming**

## 11.2 Plan Detail

Display:

- Temple Photo
- Temple Name
- Temple Address
- Planned Date
- Planned Time
- Planned Purpose
- Planned Work
- People
- Group
- Notes
- Plan Status

Actions:

- Navigate
- Add to Google Calendar
- Log This Visit
- Edit Plan
- Cancel Plan

---

## 11.3 Google Calendar Integration

Provide:

**Add to Google Calendar**

Suggested event title:

```text
Temple — Jordan River Utah Temple
```

Use:

- Planned Date
- Planned Time
- End Time
- Temple Address

Suggested event description may include:

- Temple
- Purpose
- Planned Work
- Who With
- Plan Notes

Temple Time should retain the calendar event identifier when practical so the event can later be updated or removed.

---

## 11.4 Google Maps Navigation

Provide a prominent:

**Navigate**

action.

Use:

1. Temple latitude / longitude when available.
2. Temple address as fallback.

Navigation should be available from:

- Plan Detail
- Upcoming Plan cards
- Temple Detail
- Visit Detail

---

# 12. Statistics

The Statistics section should provide meaningful historical summaries and drill-downs.

## 12.1 Reporting Period

Suggested choices:

- This Month
- This Year
- Last Year
- All Time
- Custom Date Range

Default:

**This Year**

## 12.2 Summary Cards

Suggested clickable statistics:

- Temple Visits
- Different Temples
- First-Time Temples
- Temple Work Visits
- Other Temple Visits
- Current Streak

## 12.3 Visit Streak

Calculate:

- Current Weekly Streak
- Longest Weekly Streak
- Weeks With a Temple Visit This Year

A week counts when at least one qualifying Visit occurred.

## 12.4 Temple Work Streak

Optionally calculate a separate streak based only on Visits where Temple Work was selected.

## 12.5 Visits Over Time

Possible views:

- Visits by Month
- Visits by Quarter
- Visits by Year

Charts should be clickable where practical.

## 12.6 Different Temples

Show:

- Different Temples Visited This Year
- Different Temples Visited All Time
- New Temples Visited This Year

## 12.7 Most Visited Temples

Rank Temples by number of Visits.

Selecting a Temple opens its Temple Detail page.

## 12.8 Visit Purpose Statistics

Show counts for:

- Temple Work
- Temple Grounds
- Open House
- Tour
- Family Visit
- Youth / Children Visit
- Prospective Temple-Goer Visit
- Special Event
- Other

Because a Visit may have multiple Purposes, Purpose totals do not necessarily equal Total Visits.

## 12.9 Work Performed Statistics

Show Visits containing each Work type:

- Baptisms
- Confirmations
- Initiatory
- Endowment
- Sealing
- Other

These are counts of **Visits containing that work type**, not counts of individual ordinances.

## 12.10 Work Combinations

Optionally show commonly occurring combinations:

- E
- IE
- BIE
- ES

## 12.11 People Statistics

Possible statistics:

- Person visited with most often
- Visits Together
- Different Temples Visited Together
- Most Recent Visit Together

Selecting a Person opens Person Detail.

## 12.12 Geographic Statistics

Optional summaries:

- Different Temples Visited by State / Region
- Different Temples Visited by Country

## 12.13 Visit Calendar

Optional calendar-style view showing Visit dates.

Selecting a marked day opens the Visit or Visits from that date.

## 12.14 Activity Heat Map

Optional heat-map-style view over:

- Quarter
- Year
- Last 12 Months

Blank periods should not be presented negatively.

## 12.15 Year in Review

Future enhancement:

```text
Your 2027 Temple Year

42 Visits
18 Different Temples
7 First-Time Temples
31 Temple Work Visits
11 Other Temple Experiences
23 Visits With Elaine
186 Photos
```

This summary may eventually feed into the Temple Memories Book.

---

# 13. Navigation Structure

Suggested primary navigation:

```text
Dashboard
Temples
Visits
Plans
People
Photos
Statistics
```

On mobile, the most-used destinations should remain easy to reach.

Suggested prominent mobile actions:

- Quick Log
- Plan a Visit

---

# 14. Common Card Design

Cards should use a consistent visual language throughout Temple Time.

## Temple Card

May show:

- Temple Photo
- Temple Name
- Location
- Visit Count
- Last Visit
- Favorite
- My Visit List
- Upcoming Plan

## Visit Card

May show:

- Visit or Temple Photo
- Temple Name
- Date
- Who With
- Purpose
- Work shorthand
- Favorite Visit

## Plan Card

May show:

- Temple Photo
- Temple Name
- Date / Time
- Planned Work
- Who With
- Quick actions

## Person Card

May show:

- Profile Photo
- Name
- Relationship
- Visits Together
- Temples Together

All cards should be fully clickable.

---

# 15. Empty States

Temple Time should provide useful empty-state guidance.

Examples:

## No Visits

```text
No temple visits have been recorded yet.

[Quick Log Your First Visit]
```

## Empty My Visit List

```text
Your Visit List is empty.

Add temples you intend to visit someday.
```

## No Plans

```text
No upcoming temple visits planned.

[Plan a Visit]
```

## No Filter Results

```text
No records match these filters.

[Clear Filters]
```

---

# 16. Privacy

Temple Time may contain:

- Personal journal entries
- Spiritual impressions
- Photos
- Family information
- Visit history

All content should be private to the user by default.

Nothing should be publicly shared without deliberate user action.

---

# 17. Initial Release Scope

The first major version should focus on doing the core experience very well.

## Include

- Temple management
- My Visit List
- Favorites
- Visit logging
- Quick Log
- Multiple Visit Purposes
- Multiple Work Performed selections
- People
- Group
- Notes
- Spiritual Impressions
- Memorable Experiences
- People Encountered
- Tags
- Plans
- Plan-to-Visit conversion
- Google Calendar integration
- Google Maps navigation
- Photo upload and optimization
- Photo Gallery
- Dashboard
- Clickable cards
- Core Statistics
- Search and filtering

## Defer to Later Phases

- Detailed Trip management
- Multi-temple itinerary optimization
- Expense tracking
- Hotel tracking
- Advanced route planning
- Full Temple Memories Book builder
- High-resolution print-photo storage
- Recurring Plans
- Advanced sharing

The initial data model should be designed so these future capabilities can be added without restructuring the core Temple, Visit, Person, Plan, and Photo records.

---

# 18. Future Direction

Future Temple Time capabilities may include:

- Multi-temple Trips
- Mileage and travel costs
- Hotel information
- Driving-route planning
- Temple Memories Book generation
- PDF and print export
- Year-in-Review book pages
- Map of temples visited
- Higher-resolution selected book Photos
- More advanced Nearby Temple planning
- Recurring temple Plans

These features should build on the core data already established in the first version.

---

# 19. Core Product Definition

Temple Time should ultimately help the user answer five simple questions:

> **Where have I been?**

> **Who was I with?**

> **What did I experience?**

> **Where do I intend to go next?**

> **What memories do I want to preserve?**

The application should make those answers easy to record, enjoyable to revisit, and useful for planning future temple worship.

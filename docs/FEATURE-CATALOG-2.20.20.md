# PublishRank feature catalog — 2.20.20

This catalog describes the capabilities requested for the supplied 2.20.20 plugin source. External integrations may require separate accounts, OAuth credentials, API settings, or administrator opt-in. SEO scores are editorial checklists, not ranking forecasts; schema does not guarantee rich results; indexing, traffic, rankings, and Google News inclusion are not guaranteed.

## SEO Editor and Content Optimization

- SEO title editor.
- Meta description editor.
- Primary focus keyword.
- Up to ten secondary keyphrases.
- Synonyms and related keyphrases.
- Live SEO score out of 100.
- Keyword placement analysis.
- Keyword density.
- Keyword distribution.
- Title keyword analysis.
- Description keyword analysis.
- URL slug analysis.
- Opening paragraph analysis.
- Subheading analysis.
- Image alt-text analysis.
- Content readability.
- Article intelligence.
- Content context.
- SEO recommendations.
- Search snippet preview.
- Google desktop and mobile previews.
- Facebook and LinkedIn previews.
- X/Twitter previews.
- Canonical URL.
- Robots directives.
- Open Graph metadata.
- X/Twitter metadata.
- Automatic metadata fallbacks.
- Metadata template variables.
- Per-post SEO overrides.
- Language-specific SEO metadata.
- Gutenberg integration.
- Classic Editor integration.
- Floating SEO analysis panel.
- Movable SEO editor panel.
- SEO analysis sidebar.
- Tabbed SEO editor interface.
- Live keyword field saving.

## Structured Data and Schema Engine

- Organization.
- Person.
- WebSite.
- WebPage.
- Article.
- NewsArticle.
- BlogPosting.
- ReportageNewsArticle.
- AnalysisNewsArticle.
- OpinionNewsArticle.
- BackgroundNewsArticle.
- Opinion and review-oriented article types.
- ScholarlyArticle.
- TechnicalArticle.
- Sponsored article markup.
- LiveBlogPosting.
- VideoObject.
- ImageGallery.
- AudioObject.
- PodcastEpisode.
- FAQPage.
- Event.
- Product.
- Recipe.
- Review.
- JobPosting.
- LocalBusiness.
- BreadcrumbList.
- Author-related structured data.
- Connected JSON-LD graph.
- Schema aggregation.
- Schema discovery endpoint.
- Schema validation tools.
- Live JSON-LD preview.

## Local SEO — Business Types

- 134 selectable business options across 14 niches. The options are not 134 independent Schema.org classes.
- 14 business niches.
- Business profile management.
- Business name and legal name.
- Business description.
- Address and geographical coordinates.
- Service-area businesses.
- Hidden street addresses.
- Opening hours.
- Holiday and special hours.
- Telephone and email.
- Website.
- Business logo and photographs.
- Price range.
- Payment methods.
- Accepted currencies.
- Languages.
- Areas served.
- Services and OfferCatalog.
- Booking links and ReserveAction.
- Founding date.
- Tax identification number.
- Parent organization.
- Business-specific properties.
- Restaurants: cuisine, menus and reservations.
- Hotels: check-in, check-out, rooms, official star rating and pets.
- Medical businesses: medical specialties and new-patient status.
- Banks: fees page.
- Shops and car dealerships: brands.
- Venues: capacity and free-entry information.
- Schools and veterinary businesses: appropriate LocalBusiness mappings.
- Funeral homes: LocalBusiness with additionalType where applicable.

### Business options by niche

**General (3):** Local business (generic) (`LocalBusiness`), Professional service (generic) (`ProfessionalService`), Store / shop (generic) (`Store`).
**Food & drink (10):** Food establishment (generic) (`FoodEstablishment`), Restaurant (`Restaurant`), Fast food restaurant (`FastFoodRestaurant`), Cafe / coffee shop (`CafeOrCoffeeShop`), Bakery (`Bakery`), Ice cream shop (`IceCreamShop`), Bar or pub (`BarOrPub`), Brewery (`Brewery`), Winery (`Winery`), Distillery (`Distillery`).
**Health & medical (8):** Medical business (generic) (`MedicalBusiness`), Medical clinic (`MedicalClinic`), Doctor / physician practice (`Physician`), Hospital (`Hospital`), Dentist (`Dentist`), Optician (`Optician`), Pharmacy / drugstore (`Pharmacy`), Veterinary care (`VeterinaryCare`).
**Beauty & wellness (7):** Health & beauty (generic) (`HealthAndBeautyBusiness`), Beauty salon (`BeautySalon`), Hair salon (`HairSalon`), Nail salon (`NailSalon`), Day spa (`DaySpa`), Tattoo parlor (`TattooParlor`), Health club / gym (`HealthClub`).
**Home & construction services (9):** Home & construction (generic) (`HomeAndConstructionBusiness`), Electrician (`Electrician`), Plumber (`Plumber`), Heating / ventilation / AC (HVAC) (`HVACBusiness`), Roofing contractor (`RoofingContractor`), House painter (`HousePainter`), General contractor (`GeneralContractor`), Locksmith (`Locksmith`), Moving company (`MovingCompany`).
**Automotive (10):** Automotive (generic) (`AutomotiveBusiness`), Auto body shop (`AutoBodyShop`), Car dealership (`AutoDealer`), Auto parts store (`AutoPartsStore`), Car rental (`AutoRental`), Auto repair shop (`AutoRepair`), Car wash (`AutoWash`), Gas station (`GasStation`), Motorcycle dealer (`MotorcycleDealer`), Motorcycle repair (`MotorcycleRepair`).
**Legal, finance & real estate (11):** Legal service (generic) (`LegalService`), Attorney / law firm (`Attorney`), Notary (`Notary`), Accountant / bookkeeping (`AccountingService`), Financial service (generic) (`FinancialService`), Bank or credit union (`BankOrCreditUnion`), ATM (`AutomatedTeller`), Insurance agency (`InsuranceAgency`), Real estate agency (`RealEstateAgent`), Employment / staffing agency (`EmploymentAgency`), Travel agency (`TravelAgency`).
**Lodging & travel stays (8):** Lodging (generic) (`LodgingBusiness`), Hotel (`Hotel`), Motel (`Motel`), Hostel (`Hostel`), Bed & breakfast (`BedAndBreakfast`), Vacation rental (`VacationRental`), Campground (`Campground`), Resort (`Resort`).
**Retail & shopping (30):** Shopping center / mall (`ShoppingCenter`), Bike store (`BikeStore`), Book store (`BookStore`), Clothing store (`ClothingStore`), Computer store (`ComputerStore`), Convenience store (`ConvenienceStore`), Department store (`DepartmentStore`), Electronics store (`ElectronicsStore`), Florist (`Florist`), Furniture store (`FurnitureStore`), Garden store (`GardenStore`), Grocery store (`GroceryStore`), Hardware store (`HardwareStore`), Hobby shop (`HobbyShop`), Home goods store (`HomeGoodsStore`), Jewelry store (`JewelryStore`), Liquor store (`LiquorStore`), Men's clothing store (`MensClothingStore`), Mobile phone store (`MobilePhoneStore`), Movie rental store (`MovieRentalStore`), Music store (`MusicStore`), Office equipment store (`OfficeEquipmentStore`), Outlet store (`OutletStore`), Pawn shop (`PawnShop`), Pet store (`PetStore`), Shoe store (`ShoeStore`), Sporting goods store (`SportingGoodsStore`), Tire shop (`TireShop`), Toy store (`ToyStore`), Wholesale store (`WholesaleStore`).
**Entertainment & nightlife (8):** Entertainment (generic) (`EntertainmentBusiness`), Amusement park (`AmusementPark`), Art gallery (`ArtGallery`), Casino (`Casino`), Comedy club (`ComedyClub`), Movie theater / cinema (`MovieTheater`), Nightclub (`NightClub`), Internet cafe (`InternetCafe`).
**Sports & recreation (9):** Sports venue (generic) (`SportsActivityLocation`), Bowling alley (`BowlingAlley`), Exercise gym (`ExerciseGym`), Golf course (`GolfCourse`), Public swimming pool (`PublicSwimmingPool`), Ski resort (`SkiResort`), Sports club (`SportsClub`), Stadium / arena (`StadiumOrArena`), Tennis complex (`TennisComplex`).
**Education & child care (7):** School / academy / tuition centre (generic) (`EducationalOrganization`), Child care / daycare (`ChildCare`), Preschool / nursery (`Preschool`), Primary / elementary school (`ElementarySchool`), Middle school (`MiddleSchool`), High school (`HighSchool`), College / university (`CollegeOrUniversity`).
**Services & community (7):** Funeral home (`FuneralHome`), Dry cleaner / laundry (`DryCleaningOrLaundry`), Self storage (`SelfStorage`), Recycling center (`RecyclingCenter`), Animal shelter (`AnimalShelter`), Library (`Library`), Tourist information center (`TouristInformationCenter`).
**Public services & media (7):** Government office (`GovernmentOffice`), Post office (`PostOffice`), Police station (`PoliceStation`), Fire station (`FireStation`), Emergency service (generic) (`EmergencyService`), Radio station (`RadioStation`), Television station (`TelevisionStation`).

## Technical SEO

- XML sitemap generation.
- Sitemap index.
- Post sitemap.
- Page sitemap.
- Taxonomy sitemap.
- Author sitemap.
- Image sitemap.
- Video sitemap.
- Google News sitemap.
- Sitemap browser.
- Sitemap health checks.
- Sitemap diagnostics.
- Sitemap filtering.
- Sitemap caching.
- Sitemap pagination.
- Robots.txt management.
- Global robots directives.
- Per-post robots directives.
- Noindex and nofollow.
- Crawl optimization.
- Hreflang.
- Breadcrumbs.
- Featured image preloading.
- Resource hints.
- Preconnect.
- DNS prefetch.
- Link prefetching.
- Speculation Rules.
- Feed optimization.
- llms.txt.
- llms-full.txt.
- Schema discovery.
- Site verification.
- Technical health checks.

## Search Engine Integrations

- Google Search Console.
- Google OAuth.
- Search performance.
- Search queries.
- Search pages.
- Daily and hourly metrics where implemented.
- Discover performance.
- Google News performance.
- URL Inspection.
- Property selection.
- IndexNow.
- Manual URL submission.
- Automatic IndexNow notifications.
- Baidu URL submission.
- Google Preferred Sources.

## Internal Linking and Link Intelligence

- Internal link recommendations.
- Content relationship analysis.
- Content Graph.
- Orphaned content detection.
- Link opportunities.
- Broken-link detection.
- Redirecting-link detection.
- Redirect-chain analysis.
- Sitewide broken-link checks.
- Scheduled link auditing.
- Per-article link rechecking.
- External-link checking when enabled.
- Link health reporting.

## Newsroom and Editorial Management

- Newsroom dashboard.
- Story pitches.
- Editorial assignments.
- Editorial workflow.
- Story planning.
- Editorial calendar.
- Planning board.
- Scheduled story management.
- Newsroom activity.
- Notifications.
- Newsroom configuration.
- Role-based newsroom permissions.
- Author expertise.
- Author credentials.
- Publisher Toolkit.
- Bulk SEO editing.
- Image alt-text assistance.
- Publishing workflow.
- Mastodon publishing.
- Mastodon profile verification.
- Author verification using rel="me".
- Fediverse author attribution.
- Sharing history.
- Per-post sharing controls.

## Redirects and 404 Monitoring

- 301 redirects.
- 302 redirects.
- Redirect management.
- Regex redirects where implemented.
- Redirect validation.
- Redirect-loop detection.
- 404 monitoring.
- Normalized URL paths.
- Browser/mobile/crawler categories.
- Referrer tracking.
- Searchable and sortable logs.
- Noise suppression.
- Bounded storage.
- 90-day retention.
- Redirect creation from 404 entries.

## Private Login

- Custom sign-in URL.
- Default login-route handling.
- wp-login.php handling.
- wp-register.php handling.
- wp-admin request handling.
- Custom redirect URL.
- Multisite defaults.
- Migration of existing settings.
- Emergency recovery constant:
- Dedicated administration screen.
- Login URL validation.
- Compatibility considerations.

## Integrations and Compatibility

- Gutenberg.
- Classic Editor.
- WPML.
- Polylang.
- TranslatePress.
- Weglot.
- bbPress.
- BuddyPress.
- Web Stories.
- Podcast functionality.
- JNews adapter.
- Yoast migration.
- Rank Math migration.
- All in One SEO migration.
- SEOPress migration.
- The SEO Framework migration.
- Import/export.
- WP-CLI.

## Analytics, Diagnostics and Publisher Intelligence

- Search Console performance dashboard.
- Search insights.
- SEO diagnostics.
- Sitemap diagnostics.
- Frontend scanning.
- Schema health.
- Link intelligence.
- Search engine visibility checks.
- Publisher diagnostics.
- Configuration history.
- Configuration snapshots.
- Technical health monitoring.

## Security and Administration

- Traffic Guard.
- Trusted proxy configuration.
- IP/CIDR controls.
- Country rules.
- Security headers.
- Private Login.
- Role-based access.
- Feature toggles.
- Setup Wizard.
- Dashboard.
- Configuration management.
- Migration & Portability.
- Import/export.
- Admin toolbar.
- Documentation & Help.
- WP-CLI.
- REST API.

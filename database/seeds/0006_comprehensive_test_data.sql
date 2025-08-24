-- Comprehensive test data for photoCMS - all components
-- This file creates synthetic test data for thorough system testing

-- Clear existing data (be careful in production!)
DELETE FROM album_location;
DELETE FROM album_tag;
DELETE FROM album_category;
DELETE FROM album_camera;
DELETE FROM album_lens;
DELETE FROM album_film;
DELETE FROM album_developer;
DELETE FROM album_lab;
DELETE FROM albums;
DELETE FROM categories;
DELETE FROM tags;
DELETE FROM locations;

-- Test Categories (with hierarchy and image paths)
INSERT INTO categories (id, name, slug, sort_order, parent_id, image_path) VALUES
(1, 'Street Photography', 'street-photography', 1, NULL, '/media/categories/street_1692901234.jpg'),
(2, 'Nature & Landscape', 'nature-landscape', 2, NULL, '/media/categories/nature_1692901235.jpg'),
(3, 'Portraits', 'portraits', 3, NULL, '/media/categories/portraits_1692901236.jpg'),
(4, 'Architecture', 'architecture', 4, NULL, '/media/categories/architecture_1692901237.jpg'),
(5, 'Urban Portraits', 'urban-portraits', 1, 1, NULL),
(6, 'Candid Street', 'candid-street', 2, 1, NULL),
(7, 'Mountain Photography', 'mountain-photography', 1, 2, NULL),
(8, 'Seascapes', 'seascapes', 2, 2, NULL);

-- Test Tags
INSERT INTO tags (id, name, slug) VALUES
(1, 'Black and White', 'black-and-white'),
(2, 'Film Photography', 'film-photography'),
(3, 'Digital', 'digital'),
(4, 'Analog', 'analog'),
(5, 'Long Exposure', 'long-exposure'),
(6, 'Golden Hour', 'golden-hour'),
(7, 'Blue Hour', 'blue-hour'),
(8, 'Street Art', 'street-art'),
(9, 'People', 'people'),
(10, 'Documentary', 'documentary'),
(11, 'Fine Art', 'fine-art'),
(12, 'Travel', 'travel');

-- Test Locations
INSERT INTO locations (id, name, slug, description) VALUES
(1, 'Milan City Center', 'milan-city-center', 'Historic center of Milan with Duomo and surrounding areas'),
(2, 'Venice Canals', 'venice-canals', 'Famous canals and bridges of Venice'),
(3, 'Rome Historical District', 'rome-historical-district', 'Ancient Rome area with Colosseum and Forum'),
(4, 'Tuscany Countryside', 'tuscany-countryside', 'Rolling hills and vineyards of Tuscany'),
(5, 'Cinque Terre Coast', 'cinque-terre-coast', 'Dramatic coastal villages of Cinque Terre'),
(6, 'Dolomites Mountains', 'dolomites-mountains', 'Alpine peaks and valleys in northern Italy'),
(7, 'Lake Como', 'lake-como', 'Scenic lake surrounded by mountains and villas'),
(8, 'Florence Historic Center', 'florence-historic-center', 'Renaissance art and architecture in Florence'),
(9, 'Amalfi Coast', 'amalfi-coast', 'Mediterranean coastline with cliffside towns'),
(10, 'Piedmont Wine Region', 'piedmont-wine-region', 'Wine regions of Barolo and Barbaresco');

-- Test Albums with comprehensive metadata
INSERT INTO albums (id, title, slug, category_id, excerpt, body, shoot_date, published_at, is_published, sort_order) VALUES
(1, 'Milan Street Stories', 'milan-street-stories', 1, 
    'A collection of candid moments captured in the bustling streets of Milan during autumn 2023.',
    '<p>This series explores the daily rhythm of Milanese life, from early morning coffee rituals to late evening aperitivos. Shot over three months using both film and digital cameras, the project aims to document the authentic character of Italy''s fashion capital beyond the glossy storefronts.</p><p>Each photograph tells a story of connection, solitude, or movement in urban space. The use of natural light and decisive moment techniques creates an intimate portrait of contemporary Milan.</p>',
    '2023-10-15', '2023-11-01 10:00:00', 1, 1),

(2, 'Dolomites Sunrise Series', 'dolomites-sunrise-series', 2,
    'Epic sunrise captures from the dramatic peaks of the Dolomites, shot during the golden autumn season.',
    '<p>This landscape series was created during a two-week photography expedition in the Dolomites. Each image required pre-dawn hikes to remote locations, waiting for the perfect light conditions.</p><p>The photographs showcase the interplay between dramatic mountain silhouettes and the warm alpine glow that occurs just after sunrise. Shot exclusively on medium format film to capture the full tonal range of these majestic peaks.</p>',
    '2023-09-20', '2023-10-15 14:30:00', 1, 2),

(3, 'Venetian Portraits in Blue Hour', 'venetian-portraits-blue-hour', 3,
    'Intimate portrait sessions captured during Venice''s magical blue hour, when the city transforms.',
    '<p>Working with local musicians, artists, and gondoliers, this portrait series captures the soul of Venice during the brief but magical blue hour. The challenge was balancing ambient light with subtle flash to create natural-looking portraits.</p><p>Each subject was chosen for their connection to Venetian culture, creating a documentary portrait series that goes beyond tourist imagery.</p>',
    '2023-08-10', '2023-09-05 16:20:00', 1, 3),

(4, 'Tuscan Harvest Festival', 'tuscan-harvest-festival', 4,
    'Documentary photography of traditional harvest celebrations in rural Tuscany.',
    '<p>This documentary project follows three farming families during the 2023 harvest season. From grape picking to wine making, the images capture traditions that have remained unchanged for generations.</p><p>Shot using available light techniques to maintain authenticity, the series emphasizes human connection to the land and the preservation of cultural heritage in modern times.</p>',
    '2023-09-05', '2023-10-01 12:00:00', 1, 4),

(5, 'Rome After Dark', 'rome-after-dark', 1,
    'Night photography exploration of Rome''s hidden corners and illuminated monuments.',
    '<p>This night photography series reveals a different side of Rome, away from the crowded tourist areas. Using long exposure techniques and available city lighting, the images create a moody, cinematic interpretation of the Eternal City.</p>',
    '2023-07-22', '2023-08-15 18:45:00', 1, 5);

-- Album-Category relationships (albums can belong to multiple categories)
INSERT INTO album_category (album_id, category_id) VALUES
(1, 1), (1, 5), -- Milan Street Stories: Street Photography + Urban Portraits
(2, 2), (2, 7), -- Dolomites: Nature + Mountains
(3, 3), (3, 1), -- Venice Portraits: Portraits + Street
(4, 2), (4, 4), -- Tuscany: Nature + Architecture
(5, 1), (5, 4); -- Rome: Street + Architecture

-- Album-Tag relationships
INSERT INTO album_tag (album_id, tag_id) VALUES
-- Milan Street Stories
(1, 1), (1, 2), (1, 9), (1, 10), (1, 12), -- B&W, Film, People, Documentary, Travel
-- Dolomites Sunrise
(2, 2), (2, 5), (2, 6), (2, 11), -- Film, Long Exposure, Golden Hour, Fine Art
-- Venice Portraits
(3, 2), (3, 7), (3, 9), (3, 11), (3, 12), -- Film, Blue Hour, People, Fine Art, Travel
-- Tuscan Harvest
(4, 3), (4, 9), (4, 10), (4, 12), -- Digital, People, Documentary, Travel
-- Rome After Dark
(5, 1), (5, 3), (5, 5), (5, 7); -- B&W, Digital, Long Exposure, Blue Hour

-- Album-Location relationships
INSERT INTO album_location (album_id, location_id) VALUES
(1, 1), -- Milan Street Stories → Milan City Center
(2, 6), -- Dolomites Sunrise → Dolomites Mountains
(3, 2), -- Venice Portraits → Venice Canals
(4, 4), (4, 10), -- Tuscan Harvest → Tuscany Countryside + Piedmont Wine Region
(5, 3); -- Rome After Dark → Rome Historical District

-- Album-Equipment relationships
-- Camera assignments
INSERT INTO album_camera (album_id, camera_id) VALUES
(1, 1), -- Milan: Leica M6
(1, 3), -- Milan: also with Canon EOS R5
(2, 2), -- Dolomites: Mamiya RB67
(3, 1), -- Venice: Leica M6
(4, 3), -- Tuscany: Canon EOS R5
(5, 4); -- Rome: Nikon D850

-- Lens assignments
INSERT INTO album_lens (album_id, lens_id) VALUES
(1, 1), -- Milan: Leica Summicron 50mm
(1, 2), -- Milan: also Leica Summilux 35mm
(2, 3), -- Dolomites: Mamiya 127mm
(3, 2), -- Venice: Leica Summilux 35mm
(4, 4), -- Tuscany: Canon RF 24-70mm
(5, 5); -- Rome: Nikon 14-24mm

-- Film assignments
INSERT INTO album_film (album_id, film_id) VALUES
(1, 1), -- Milan: Kodak Tri-X 400
(1, 2), -- Milan: also Ilford HP5+
(2, 3), -- Dolomites: Fuji Velvia 50
(3, 1); -- Venice: Kodak Tri-X 400

-- Developer assignments (only for film photos)
INSERT INTO album_developer (album_id, developer_id) VALUES
(1, 1), -- Milan: Kodak D-76
(2, 2), -- Dolomites: Kodak E-6
(3, 3); -- Venice: Ilford DDX

-- Lab assignments
INSERT INTO album_lab (album_id, lab_id) VALUES
(1, 1), -- Milan: Gamma Lab Milano
(2, 2), -- Dolomites: Noritsu Pro Lab
(3, 1), -- Venice: Gamma Lab Milano
(4, 3); -- Tuscany: Digital Workflow
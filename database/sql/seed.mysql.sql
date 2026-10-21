-- Seed data: subject taxonomy (12 domains) with BSL's own courses flagged
-- is_bsl = TRUE so they can be featured first in the catalog and search.
--
-- Restructured from an original 9 domains: three grab-bag domains were
-- split into coherent pairs once their subject lists grew past what the
-- original name meant — "Performing & creative arts" into Music &
-- performing arts / Visual arts & design; "Vocational, trades & design"
-- into Skilled trades (design moved into Visual arts & design above);
-- "Business, finance & marketing" into Business & finance / Marketing &
-- sales. No subjects were dropped in the restructure.

INSERT INTO subject_domains (name, icon, sort_order) VALUES
  ('English & exams',              'ti-certificate',   1),
  ('K-12 academics',                'ti-school',        2),
  ('Test prep & admissions',        'ti-certificate',   3),
  ('Languages',                     'ti-language',      4),
  ('Software & tech',               'ti-code',          5),
  ('Music & performing arts',       'ti-piano',         6),
  ('Visual arts & design',          'ti-palette',       7),
  ('Skilled trades',                'ti-tool',          8),
  ('Business & finance',            'ti-briefcase',     9),
  ('Marketing & sales',             'ti-speakerphone',  10),
  ('Health & fitness',              'ti-yoga',          11),
  ('Hobbies & culinary',            'ti-chef-hat',      12);

-- =========================================================
-- 1. English & exams (BSL priority — all BSL's own)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, TRUE FROM subject_domains, (SELECT 'English Speaking' AS name UNION ALL SELECT 'Personality Development' AS name UNION ALL SELECT 'Corporate Training' AS name UNION ALL SELECT 'IELTS (English Test for Study/Work Abroad)' AS name UNION ALL SELECT 'PTE (English Test for Visa & Immigration)' AS name UNION ALL SELECT 'OET (English Test for Healthcare Workers)' AS name UNION ALL SELECT 'TEFL Certification (Become an English Teacher Abroad)' AS name UNION ALL SELECT 'TESOL Certification (Teach English to Non-Native Speakers)' AS name UNION ALL SELECT 'ESL Certification (Teach English as a Second Language)' AS name) AS x WHERE subject_domains.name = 'English & exams';

-- =========================================================
-- 2. K-12 academics
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Early Math' AS name UNION ALL SELECT 'Pre-Algebra' AS name UNION ALL SELECT 'Algebra I & II' AS name UNION ALL SELECT 'Geometry' AS name UNION ALL SELECT 'Trigonometry' AS name UNION ALL SELECT 'Pre-Calculus' AS name UNION ALL SELECT 'AP/IB Calculus' AS name UNION ALL SELECT 'Mental Math / Abacus' AS name UNION ALL SELECT 'Vedic Mathematics' AS name UNION ALL SELECT 'Statistics' AS name UNION ALL SELECT 'General Science' AS name UNION ALL SELECT 'Physics' AS name UNION ALL SELECT 'Chemistry' AS name UNION ALL SELECT 'Biology' AS name UNION ALL SELECT 'Earth & Environmental Science' AS name UNION ALL SELECT 'Computer Science' AS name UNION ALL SELECT 'Reading & Phonics' AS name UNION ALL SELECT 'English Literature' AS name UNION ALL SELECT 'Creative Writing' AS name UNION ALL SELECT 'Grammar & Rhetoric' AS name UNION ALL SELECT 'World History' AS name UNION ALL SELECT 'Human Geography' AS name UNION ALL SELECT 'Civics & Political Science' AS name UNION ALL SELECT 'Economics' AS name UNION ALL SELECT 'Psychology' AS name UNION ALL SELECT 'Sociology' AS name UNION ALL SELECT 'Music Theory' AS name UNION ALL SELECT 'Special Needs / SEN Tutoring' AS name) AS x WHERE subject_domains.name = 'K-12 academics';

-- =========================================================
-- 3. Test prep & admissions
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'SAT (US College Entrance Test)' AS name UNION ALL SELECT 'ACT (US College Entrance Test)' AS name UNION ALL SELECT 'PSAT (Practice Test for the SAT)' AS name UNION ALL SELECT 'AP Exams (US Advanced High School Courses)' AS name UNION ALL SELECT 'IB Diploma Prep (International High School Diploma)' AS name UNION ALL SELECT '11+ / Common Entrance' AS name UNION ALL SELECT 'GRE (Grad School Entrance Test)' AS name UNION ALL SELECT 'GMAT (MBA Entrance Test)' AS name UNION ALL SELECT 'LSAT (Law School Entrance Test)' AS name UNION ALL SELECT 'MCAT (Medical School Entrance Test)' AS name UNION ALL SELECT 'DAT (Dental School Entrance Test)' AS name UNION ALL SELECT 'OAT (Optometry School Entrance Test)' AS name UNION ALL SELECT 'GAMSAT (Medical School Entrance Test)' AS name UNION ALL SELECT 'CAT (MBA Entrance Test, India)' AS name UNION ALL SELECT 'TOEFL iBT (English Test for US Study)' AS name UNION ALL SELECT 'Duolingo English Test' AS name UNION ALL SELECT 'DELF/DALF (French Proficiency Test)' AS name UNION ALL SELECT 'DELE (Spanish Proficiency Test)' AS name UNION ALL SELECT 'Goethe-Zertifikat (German Proficiency Test)' AS name UNION ALL SELECT 'JLPT (Japanese Proficiency Test)' AS name UNION ALL SELECT 'HSK (Chinese Proficiency Test)' AS name UNION ALL SELECT 'CBSE Board Exam Prep' AS name UNION ALL SELECT 'ICSE Board Exam Prep' AS name UNION ALL SELECT 'JEE (Engineering Entrance Exam)' AS name UNION ALL SELECT 'NEET (Medical Entrance Exam)' AS name UNION ALL SELECT 'CTET / TET (Teacher Eligibility Test)' AS name UNION ALL SELECT 'NDA / CDS (Armed Forces Entrance Exam)' AS name UNION ALL SELECT 'CLAT (Law Entrance Exam)' AS name UNION ALL SELECT 'Olympiad Exams (NSO/IMO/NTSE)' AS name UNION ALL SELECT 'UPSC (Civil Services Exam)' AS name UNION ALL SELECT 'SSC (Government Job Exam)' AS name UNION ALL SELECT 'Banking Exams' AS name UNION ALL SELECT 'GATE (Engineering PG Entrance Exam)' AS name UNION ALL SELECT 'NCLEX (US Nursing License Exam)' AS name UNION ALL SELECT 'USMLE / PLAB (Medical License Exam)' AS name UNION ALL SELECT 'Bar Exam' AS name UNION ALL SELECT 'CFA (Finance Certification)' AS name UNION ALL SELECT 'CPA (Accounting Certification)' AS name) AS x WHERE subject_domains.name = 'Test prep & admissions';

-- =========================================================
-- 4. Languages (BSL's 12 core languages flagged is_bsl)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, TRUE FROM subject_domains, (SELECT 'French' AS name UNION ALL SELECT 'German' AS name UNION ALL SELECT 'Spanish' AS name UNION ALL SELECT 'Hindi' AS name UNION ALL SELECT 'Japanese' AS name UNION ALL SELECT 'Mandarin Chinese' AS name UNION ALL SELECT 'Russian' AS name UNION ALL SELECT 'Portuguese' AS name UNION ALL SELECT 'Italian' AS name UNION ALL SELECT 'Arabic' AS name UNION ALL SELECT 'Korean' AS name) AS x WHERE subject_domains.name = 'Languages';

INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Bengali' AS name UNION ALL SELECT 'Tamil' AS name UNION ALL SELECT 'Telugu' AS name UNION ALL SELECT 'Marathi' AS name UNION ALL SELECT 'Gujarati' AS name UNION ALL SELECT 'Punjabi' AS name UNION ALL SELECT 'Urdu' AS name UNION ALL SELECT 'Kannada' AS name UNION ALL SELECT 'Odia' AS name UNION ALL SELECT 'Assamese' AS name UNION ALL SELECT 'Sanskrit' AS name UNION ALL SELECT 'Turkish' AS name UNION ALL SELECT 'Vietnamese' AS name UNION ALL SELECT 'Swahili' AS name UNION ALL SELECT 'Dutch' AS name UNION ALL SELECT 'Polish' AS name UNION ALL SELECT 'Greek' AS name UNION ALL SELECT 'Hebrew' AS name UNION ALL SELECT 'Persian/Farsi' AS name UNION ALL SELECT 'Swedish' AS name UNION ALL SELECT 'Irish Gaelic' AS name UNION ALL SELECT 'Thai' AS name UNION ALL SELECT 'Indonesian' AS name UNION ALL SELECT 'Filipino/Tagalog' AS name UNION ALL SELECT 'Norwegian' AS name UNION ALL SELECT 'Danish' AS name UNION ALL SELECT 'Finnish' AS name UNION ALL SELECT 'Business English' AS name UNION ALL SELECT 'Accent Reduction' AS name UNION ALL SELECT 'Conversational Practice' AS name UNION ALL SELECT 'Medical/Legal Terminology' AS name UNION ALL SELECT 'Sign Language (American, British, Indian)' AS name) AS x WHERE subject_domains.name = 'Languages';

-- =========================================================
-- 5. Software & tech
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (SELECT 'Python' AS name, FALSE AS is_bsl UNION ALL SELECT 'JavaScript' AS name, FALSE AS is_bsl UNION ALL SELECT 'TypeScript' AS name, FALSE AS is_bsl UNION ALL SELECT 'C++' AS name, FALSE AS is_bsl UNION ALL SELECT 'C#' AS name, FALSE AS is_bsl UNION ALL SELECT 'Java' AS name, FALSE AS is_bsl UNION ALL SELECT 'Go' AS name, FALSE AS is_bsl UNION ALL SELECT 'Rust' AS name, FALSE AS is_bsl UNION ALL SELECT 'Swift' AS name, FALSE AS is_bsl UNION ALL SELECT 'Kotlin' AS name, FALSE AS is_bsl UNION ALL SELECT 'PHP' AS name, FALSE AS is_bsl UNION ALL SELECT 'Ruby' AS name, FALSE AS is_bsl UNION ALL SELECT 'SQL' AS name, FALSE AS is_bsl UNION ALL SELECT 'Data Structures & Algorithms (Coding Interview Prep)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Full-Stack Web Dev' AS name, FALSE AS is_bsl UNION ALL SELECT 'Mobile App Dev' AS name, FALSE AS is_bsl UNION ALL SELECT 'Database Management' AS name, FALSE AS is_bsl UNION ALL SELECT 'Game Development' AS name, FALSE AS is_bsl UNION ALL SELECT 'Machine Learning (Teaching Computers to Learn)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Deep Learning (Advanced AI Techniques)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Data Analytics (Making Sense of Data)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Computer Vision (Teaching Computers to See)' AS name, FALSE AS is_bsl UNION ALL SELECT 'NLP (Teaching Computers to Understand Language)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Prompt Engineering (Writing Effective AI Instructions)' AS name, TRUE AS is_bsl UNION ALL SELECT 'AWS (Cloud Computing by Amazon)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Google Cloud Platform (Cloud Computing by Google)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Microsoft Azure (Cloud Computing by Microsoft)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Ethical Hacking (Protecting Systems from Hackers)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Cyber Defense (Protecting Computers from Attacks)' AS name, FALSE AS is_bsl UNION ALL SELECT 'DevOps (Software Deployment & Operations)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Blockchain / Web3 (Cryptocurrency & Decentralized Tech)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Salesforce Admin (Managing Salesforce Software)' AS name, FALSE AS is_bsl UNION ALL SELECT 'QA / Software Testing (Finding Bugs in Software)' AS name, FALSE AS is_bsl UNION ALL SELECT 'CompTIA / CISSP Certifications (IT Security Certifications)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Coding for Kids' AS name, TRUE AS is_bsl UNION ALL SELECT 'Artificial Intelligence' AS name, TRUE AS is_bsl) AS x WHERE subject_domains.name = 'Software & tech';

-- =========================================================
-- 6. Music & performing arts
-- (instruments, vocal, dance — split out of the old "Performing &
-- creative arts" domain; visual arts moved to Visual arts & design)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Piano/Keyboard' AS name UNION ALL SELECT 'Guitar' AS name UNION ALL SELECT 'Bass' AS name UNION ALL SELECT 'Violin' AS name UNION ALL SELECT 'Viola' AS name UNION ALL SELECT 'Cello' AS name UNION ALL SELECT 'Drums/Percussion' AS name UNION ALL SELECT 'Flute' AS name UNION ALL SELECT 'Saxophone' AS name UNION ALL SELECT 'Trumpet' AS name UNION ALL SELECT 'Ukulele' AS name UNION ALL SELECT 'Sitar' AS name UNION ALL SELECT 'Tabla' AS name UNION ALL SELECT 'Oud' AS name UNION ALL SELECT 'Guzheng' AS name UNION ALL SELECT 'Harmonium' AS name UNION ALL SELECT 'Classical Vocal' AS name UNION ALL SELECT 'Contemporary Vocal' AS name UNION ALL SELECT 'Hindustani Classical Vocal' AS name UNION ALL SELECT 'Carnatic Vocal' AS name UNION ALL SELECT 'Opera' AS name UNION ALL SELECT 'Voice Coaching' AS name UNION ALL SELECT 'Public Speaking' AS name UNION ALL SELECT 'Acting' AS name UNION ALL SELECT 'Stand-up Comedy' AS name UNION ALL SELECT 'Voice Over / Dubbing' AS name UNION ALL SELECT 'RJ & Anchoring Training' AS name UNION ALL SELECT 'Ballet' AS name UNION ALL SELECT 'Contemporary Dance' AS name UNION ALL SELECT 'Hip-Hop' AS name UNION ALL SELECT 'Salsa' AS name UNION ALL SELECT 'Tango' AS name UNION ALL SELECT 'Ballroom' AS name UNION ALL SELECT 'Kathak' AS name UNION ALL SELECT 'Bharatanatyam' AS name UNION ALL SELECT 'Tap Dance' AS name) AS x WHERE subject_domains.name = 'Music & performing arts';

-- =========================================================
-- 7. Visual arts & design
-- (visual arts from the old "Performing & creative arts" domain, plus
-- design subjects previously under "Vocational, trades & design")
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Drawing & Sketching' AS name UNION ALL SELECT 'Oil & Acrylic Painting' AS name UNION ALL SELECT 'Watercolor' AS name UNION ALL SELECT 'Digital Art & Illustration' AS name UNION ALL SELECT '3D Modeling' AS name UNION ALL SELECT 'Animation' AS name UNION ALL SELECT 'Sculpting' AS name UNION ALL SELECT 'Calligraphy' AS name UNION ALL SELECT 'Graphic Design' AS name UNION ALL SELECT 'UI/UX Design' AS name UNION ALL SELECT 'Fashion Design' AS name UNION ALL SELECT 'Garment Construction' AS name UNION ALL SELECT 'Textile Design' AS name UNION ALL SELECT 'Industrial Design' AS name) AS x WHERE subject_domains.name = 'Visual arts & design';

-- =========================================================
-- 8. Skilled trades
-- (hands-on trades from the old "Vocational, trades & design" domain;
-- design subjects moved to Visual arts & design above)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Automotive Repair' AS name UNION ALL SELECT 'Electrical Work' AS name UNION ALL SELECT 'Plumbing' AS name UNION ALL SELECT 'Carpentry' AS name UNION ALL SELECT 'Welding' AS name UNION ALL SELECT 'HVAC Maintenance' AS name UNION ALL SELECT 'AutoCAD' AS name UNION ALL SELECT 'Revit' AS name UNION ALL SELECT 'SketchUp' AS name UNION ALL SELECT 'Interior Architecture' AS name UNION ALL SELECT 'Civil Drafting' AS name UNION ALL SELECT 'BIM' AS name UNION ALL SELECT 'Cosmetology / Barbering' AS name UNION ALL SELECT 'Professional Culinary Arts' AS name UNION ALL SELECT 'Real Estate Licensing' AS name UNION ALL SELECT 'Driving Lessons' AS name UNION ALL SELECT 'Makeup Artistry' AS name UNION ALL SELECT 'Air Hostess / Cabin Crew Training' AS name) AS x WHERE subject_domains.name = 'Skilled trades';

-- =========================================================
-- 9. Business & finance
-- (accounting, finance, and management — split out of the old
-- "Business, finance & marketing" domain; marketing/sales moved to
-- Marketing & sales below)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (SELECT 'Financial Accounting' AS name UNION ALL SELECT 'Managerial Accounting (Accounting for Business Decisions)' AS name UNION ALL SELECT 'Corporate Finance (Managing Company Money)' AS name UNION ALL SELECT 'Investment Banking Prep (Careers in Finance)' AS name UNION ALL SELECT 'Stock Market Trading' AS name UNION ALL SELECT 'Financial Modeling (Building Business Forecasts in Excel)' AS name UNION ALL SELECT 'QuickBooks' AS name UNION ALL SELECT 'Project Management (PMP/Agile)' AS name UNION ALL SELECT 'Business Strategy' AS name UNION ALL SELECT 'Operations Management (Running Business Processes Smoothly)' AS name UNION ALL SELECT 'Supply Chain & Logistics (Moving Goods Efficiently)' AS name UNION ALL SELECT 'Human Resources' AS name UNION ALL SELECT 'Entrepreneurship / Startup Fundamentals' AS name UNION ALL SELECT 'Event Management' AS name UNION ALL SELECT 'Public Relations' AS name UNION ALL SELECT 'Excel / Spreadsheets' AS name) AS x WHERE subject_domains.name = 'Business & finance';

-- =========================================================
-- 10. Marketing & sales
-- (split out of the old "Business, finance & marketing" domain.
-- Digital Marketing is BSL's own named course and is the BSL-flagged
-- umbrella here, alongside its component skills.)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (SELECT 'Digital Marketing' AS name, TRUE AS is_bsl UNION ALL SELECT 'SEO (Getting Found on Google)' AS name, FALSE AS is_bsl UNION ALL SELECT 'PPC Advertising (Paid Online Ads)' AS name, FALSE AS is_bsl UNION ALL SELECT 'Content Marketing' AS name, FALSE AS is_bsl UNION ALL SELECT 'Content Writing' AS name, FALSE AS is_bsl UNION ALL SELECT 'Copywriting' AS name, FALSE AS is_bsl UNION ALL SELECT 'Social Media Strategy' AS name, FALSE AS is_bsl UNION ALL SELECT 'Email Marketing' AS name, FALSE AS is_bsl UNION ALL SELECT 'Negotiation Skills' AS name, FALSE AS is_bsl UNION ALL SELECT 'Sales Training' AS name, FALSE AS is_bsl) AS x WHERE subject_domains.name = 'Marketing & sales';

-- =========================================================
-- 11. Health & fitness
-- (split out of the old "Health, fitness & hobbies" domain; culinary
-- and general hobbies moved to Hobbies & culinary below)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (SELECT 'Personal Fitness Training' AS name, FALSE AS is_bsl UNION ALL SELECT 'Pilates' AS name, FALSE AS is_bsl UNION ALL SELECT 'Yoga' AS name, TRUE AS is_bsl UNION ALL SELECT 'Martial Arts' AS name, FALSE AS is_bsl UNION ALL SELECT 'Swimming' AS name, FALSE AS is_bsl UNION ALL SELECT 'Tennis/Padel' AS name, FALSE AS is_bsl UNION ALL SELECT 'Golf' AS name, FALSE AS is_bsl UNION ALL SELECT 'Cricket' AS name, FALSE AS is_bsl UNION ALL SELECT 'Football / Soccer' AS name, FALSE AS is_bsl UNION ALL SELECT 'Badminton' AS name, FALSE AS is_bsl UNION ALL SELECT 'Table Tennis' AS name, FALSE AS is_bsl UNION ALL SELECT 'Basketball' AS name, FALSE AS is_bsl UNION ALL SELECT 'Volleyball' AS name, FALSE AS is_bsl UNION ALL SELECT 'Hockey' AS name, FALSE AS is_bsl UNION ALL SELECT 'Kabaddi' AS name, FALSE AS is_bsl UNION ALL SELECT 'Self-Defense' AS name, FALSE AS is_bsl UNION ALL SELECT 'Nutrition & Dietetics' AS name, FALSE AS is_bsl UNION ALL SELECT 'Meditation / Mindfulness' AS name, FALSE AS is_bsl UNION ALL SELECT 'First Aid / CPR Certification' AS name, FALSE AS is_bsl) AS x WHERE subject_domains.name = 'Health & fitness';

-- =========================================================
-- 12. Hobbies & culinary
-- (split out of the old "Health, fitness & hobbies" domain)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (SELECT 'Cooking Fundamentals' AS name, FALSE AS is_bsl UNION ALL SELECT 'Baking & Pastry Arts' AS name, FALSE AS is_bsl UNION ALL SELECT 'Mixology' AS name, FALSE AS is_bsl UNION ALL SELECT 'Food Safety' AS name, FALSE AS is_bsl UNION ALL SELECT 'Chess' AS name, FALSE AS is_bsl UNION ALL SELECT 'Bridge' AS name, FALSE AS is_bsl UNION ALL SELECT 'Photography' AS name, FALSE AS is_bsl UNION ALL SELECT 'Videography & Editing' AS name, FALSE AS is_bsl UNION ALL SELECT 'Audio Engineering & Music Production' AS name, FALSE AS is_bsl UNION ALL SELECT 'Vedic Astrology / Kundli' AS name, TRUE AS is_bsl UNION ALL SELECT 'Vastu Shastra' AS name, FALSE AS is_bsl UNION ALL SELECT 'Gardening' AS name, FALSE AS is_bsl UNION ALL SELECT 'Sewing / Knitting' AS name, FALSE AS is_bsl) AS x WHERE subject_domains.name = 'Hobbies & culinary';

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
SELECT id, x.name, TRUE FROM subject_domains, (VALUES
  ('English Speaking'), ('Personality Development'), ('Corporate Training'),
  ('IELTS (English Test for Study/Work Abroad)'), ('PTE (English Test for Visa & Immigration)'), ('OET (English Test for Healthcare Workers)'), ('TEFL Certification (Become an English Teacher Abroad)'), ('TESOL Certification (Teach English to Non-Native Speakers)'), ('ESL Certification (Teach English as a Second Language)')
) AS x(name)
WHERE subject_domains.name = 'English & exams';

-- =========================================================
-- 2. K-12 academics
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Early Math'), ('Pre-Algebra'), ('Algebra I & II'), ('Geometry'), ('Trigonometry'),
  ('Pre-Calculus'), ('AP/IB Calculus'), ('Mental Math / Abacus'), ('Vedic Mathematics'), ('Statistics'),
  ('General Science'), ('Physics'), ('Chemistry'), ('Biology'), ('Earth & Environmental Science'), ('Computer Science'),
  ('Reading & Phonics'), ('English Literature'), ('Creative Writing'), ('Grammar & Rhetoric'),
  ('World History'), ('Human Geography'), ('Civics & Political Science'), ('Economics'),
  ('Psychology'), ('Sociology'), ('Music Theory'), ('Special Needs / SEN Tutoring')
) AS x(name)
WHERE subject_domains.name = 'K-12 academics';

-- =========================================================
-- 3. Test prep & admissions
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('SAT (US College Entrance Test)'), ('ACT (US College Entrance Test)'), ('PSAT (Practice Test for the SAT)'), ('AP Exams (US Advanced High School Courses)'), ('IB Diploma Prep (International High School Diploma)'), ('11+ / Common Entrance'),
  ('GRE (Grad School Entrance Test)'), ('GMAT (MBA Entrance Test)'), ('LSAT (Law School Entrance Test)'), ('MCAT (Medical School Entrance Test)'), ('DAT (Dental School Entrance Test)'), ('OAT (Optometry School Entrance Test)'), ('GAMSAT (Medical School Entrance Test)'), ('CAT (MBA Entrance Test, India)'),
  ('TOEFL iBT (English Test for US Study)'), ('Duolingo English Test'), ('DELF/DALF (French Proficiency Test)'), ('DELE (Spanish Proficiency Test)'), ('Goethe-Zertifikat (German Proficiency Test)'), ('JLPT (Japanese Proficiency Test)'), ('HSK (Chinese Proficiency Test)'),
  ('CBSE Board Exam Prep'), ('ICSE Board Exam Prep'), ('JEE (Engineering Entrance Exam)'), ('NEET (Medical Entrance Exam)'),
  ('CTET / TET (Teacher Eligibility Test)'), ('NDA / CDS (Armed Forces Entrance Exam)'), ('CLAT (Law Entrance Exam)'), ('Olympiad Exams (NSO/IMO/NTSE)'),
  ('UPSC (Civil Services Exam)'), ('SSC (Government Job Exam)'), ('Banking Exams'), ('GATE (Engineering PG Entrance Exam)'),
  ('NCLEX (US Nursing License Exam)'), ('USMLE / PLAB (Medical License Exam)'), ('Bar Exam'), ('CFA (Finance Certification)'), ('CPA (Accounting Certification)')
) AS x(name)
WHERE subject_domains.name = 'Test prep & admissions';

-- =========================================================
-- 4. Languages (BSL's 12 core languages flagged is_bsl)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, TRUE FROM subject_domains, (VALUES
  ('French'), ('German'), ('Spanish'), ('Hindi'), ('Japanese'), ('Mandarin Chinese'),
  ('Russian'), ('Portuguese'), ('Italian'), ('Arabic'), ('Korean')
) AS x(name)
WHERE subject_domains.name = 'Languages';

INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Bengali'), ('Tamil'), ('Telugu'), ('Marathi'), ('Gujarati'), ('Punjabi'), ('Urdu'),
  ('Kannada'), ('Odia'), ('Assamese'), ('Sanskrit'),
  ('Turkish'), ('Vietnamese'), ('Swahili'), ('Dutch'), ('Polish'), ('Greek'),
  ('Hebrew'), ('Persian/Farsi'), ('Swedish'), ('Irish Gaelic'),
  ('Thai'), ('Indonesian'), ('Filipino/Tagalog'), ('Norwegian'), ('Danish'), ('Finnish'),
  ('Business English'), ('Accent Reduction'), ('Conversational Practice'),
  ('Medical/Legal Terminology'), ('Sign Language (American, British, Indian)')
) AS x(name)
WHERE subject_domains.name = 'Languages';

-- =========================================================
-- 5. Software & tech
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (VALUES
  ('Python', FALSE), ('JavaScript', FALSE), ('TypeScript', FALSE), ('C++', FALSE), ('C#', FALSE),
  ('Java', FALSE), ('Go', FALSE), ('Rust', FALSE), ('Swift', FALSE), ('Kotlin', FALSE), ('PHP', FALSE),
  ('Ruby', FALSE), ('SQL', FALSE), ('Data Structures & Algorithms (Coding Interview Prep)', FALSE),
  ('Full-Stack Web Dev', FALSE), ('Mobile App Dev', FALSE), ('Database Management', FALSE), ('Game Development', FALSE),
  ('Machine Learning (Teaching Computers to Learn)', FALSE), ('Deep Learning (Advanced AI Techniques)', FALSE), ('Data Analytics (Making Sense of Data)', FALSE),
  ('Computer Vision (Teaching Computers to See)', FALSE), ('NLP (Teaching Computers to Understand Language)', FALSE), ('Prompt Engineering (Writing Effective AI Instructions)', TRUE),
  ('AWS (Cloud Computing by Amazon)', FALSE), ('Google Cloud Platform (Cloud Computing by Google)', FALSE), ('Microsoft Azure (Cloud Computing by Microsoft)', FALSE),
  ('Ethical Hacking (Protecting Systems from Hackers)', FALSE), ('Cyber Defense (Protecting Computers from Attacks)', FALSE), ('DevOps (Software Deployment & Operations)', FALSE), ('Blockchain / Web3 (Cryptocurrency & Decentralized Tech)', FALSE),
  ('Salesforce Admin (Managing Salesforce Software)', FALSE), ('QA / Software Testing (Finding Bugs in Software)', FALSE), ('CompTIA / CISSP Certifications (IT Security Certifications)', FALSE),
  ('Coding for Kids', TRUE), ('Artificial Intelligence', TRUE)
) AS x(name, is_bsl)
WHERE subject_domains.name = 'Software & tech';

-- =========================================================
-- 6. Music & performing arts
-- (instruments, vocal, dance — split out of the old "Performing &
-- creative arts" domain; visual arts moved to Visual arts & design)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Piano/Keyboard'), ('Guitar'), ('Bass'), ('Violin'), ('Viola'), ('Cello'),
  ('Drums/Percussion'), ('Flute'), ('Saxophone'), ('Trumpet'), ('Ukulele'),
  ('Sitar'), ('Tabla'), ('Oud'), ('Guzheng'), ('Harmonium'),
  ('Classical Vocal'), ('Contemporary Vocal'), ('Hindustani Classical Vocal'), ('Carnatic Vocal'),
  ('Opera'), ('Voice Coaching'), ('Public Speaking'), ('Acting'), ('Stand-up Comedy'),
  ('Voice Over / Dubbing'), ('RJ & Anchoring Training'),
  ('Ballet'), ('Contemporary Dance'), ('Hip-Hop'), ('Salsa'), ('Tango'), ('Ballroom'),
  ('Kathak'), ('Bharatanatyam'), ('Tap Dance')
) AS x(name)
WHERE subject_domains.name = 'Music & performing arts';

-- =========================================================
-- 7. Visual arts & design
-- (visual arts from the old "Performing & creative arts" domain, plus
-- design subjects previously under "Vocational, trades & design")
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Drawing & Sketching'), ('Oil & Acrylic Painting'), ('Watercolor'),
  ('Digital Art & Illustration'), ('3D Modeling'), ('Animation'), ('Sculpting'), ('Calligraphy'),
  ('Graphic Design'), ('UI/UX Design'), ('Fashion Design'),
  ('Garment Construction'), ('Textile Design'), ('Industrial Design')
) AS x(name)
WHERE subject_domains.name = 'Visual arts & design';

-- =========================================================
-- 8. Skilled trades
-- (hands-on trades from the old "Vocational, trades & design" domain;
-- design subjects moved to Visual arts & design above)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Automotive Repair'), ('Electrical Work'), ('Plumbing'), ('Carpentry'), ('Welding'), ('HVAC Maintenance'),
  ('AutoCAD'), ('Revit'), ('SketchUp'), ('Interior Architecture'), ('Civil Drafting'), ('BIM'),
  ('Cosmetology / Barbering'), ('Professional Culinary Arts'), ('Real Estate Licensing'),
  ('Driving Lessons'), ('Makeup Artistry'), ('Air Hostess / Cabin Crew Training')
) AS x(name)
WHERE subject_domains.name = 'Skilled trades';

-- =========================================================
-- 9. Business & finance
-- (accounting, finance, and management — split out of the old
-- "Business, finance & marketing" domain; marketing/sales moved to
-- Marketing & sales below)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, FALSE FROM subject_domains, (VALUES
  ('Financial Accounting'), ('Managerial Accounting (Accounting for Business Decisions)'), ('Corporate Finance (Managing Company Money)'),
  ('Investment Banking Prep (Careers in Finance)'), ('Stock Market Trading'), ('Financial Modeling (Building Business Forecasts in Excel)'), ('QuickBooks'),
  ('Project Management (PMP/Agile)'), ('Business Strategy'), ('Operations Management (Running Business Processes Smoothly)'),
  ('Supply Chain & Logistics (Moving Goods Efficiently)'), ('Human Resources'), ('Entrepreneurship / Startup Fundamentals'),
  ('Event Management'), ('Public Relations'),
  ('Excel / Spreadsheets')
) AS x(name)
WHERE subject_domains.name = 'Business & finance';

-- =========================================================
-- 10. Marketing & sales
-- (split out of the old "Business, finance & marketing" domain.
-- Digital Marketing is BSL's own named course and is the BSL-flagged
-- umbrella here, alongside its component skills.)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (VALUES
  ('Digital Marketing', TRUE),
  ('SEO (Getting Found on Google)', FALSE), ('PPC Advertising (Paid Online Ads)', FALSE), ('Content Marketing', FALSE),
  ('Content Writing', FALSE), ('Copywriting', FALSE),
  ('Social Media Strategy', FALSE), ('Email Marketing', FALSE),
  ('Negotiation Skills', FALSE), ('Sales Training', FALSE)
) AS x(name, is_bsl)
WHERE subject_domains.name = 'Marketing & sales';

-- =========================================================
-- 11. Health & fitness
-- (split out of the old "Health, fitness & hobbies" domain; culinary
-- and general hobbies moved to Hobbies & culinary below)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (VALUES
  ('Personal Fitness Training', FALSE), ('Pilates', FALSE), ('Yoga', TRUE),
  ('Martial Arts', FALSE), ('Swimming', FALSE), ('Tennis/Padel', FALSE), ('Golf', FALSE),
  ('Cricket', FALSE), ('Football / Soccer', FALSE),
  ('Badminton', FALSE), ('Table Tennis', FALSE), ('Basketball', FALSE), ('Volleyball', FALSE),
  ('Hockey', FALSE), ('Kabaddi', FALSE), ('Self-Defense', FALSE),
  ('Nutrition & Dietetics', FALSE), ('Meditation / Mindfulness', FALSE), ('First Aid / CPR Certification', FALSE)
) AS x(name, is_bsl)
WHERE subject_domains.name = 'Health & fitness';

-- =========================================================
-- 12. Hobbies & culinary
-- (split out of the old "Health, fitness & hobbies" domain)
-- =========================================================
INSERT INTO subjects (domain_id, name, is_bsl)
SELECT id, x.name, x.is_bsl FROM subject_domains, (VALUES
  ('Cooking Fundamentals', FALSE), ('Baking & Pastry Arts', FALSE), ('Mixology', FALSE), ('Food Safety', FALSE),
  ('Chess', FALSE), ('Bridge', FALSE), ('Photography', FALSE), ('Videography & Editing', FALSE),
  ('Audio Engineering & Music Production', FALSE), ('Vedic Astrology / Kundli', TRUE), ('Vastu Shastra', FALSE),
  ('Gardening', FALSE), ('Sewing / Knitting', FALSE)
) AS x(name, is_bsl)
WHERE subject_domains.name = 'Hobbies & culinary';

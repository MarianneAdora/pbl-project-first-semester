<?php
require_once 'src/Services/GeminiService.php';

// Mock CV Text (Simulating what you would extract from a PDF)
$mockCvText = "
John Doe
Email: johndoe@email.com | Phone: 09123456789

EDUCATION:
BS in Computer Science, University of the Philippines, 2020

WORK EXPERIENCE:
Customer Service Representative, Telco BPO, 2021-2023
- Handled inbound calls and resolved customer complaints.
";

// The Prompt
$prompt = "
You are an AI assistant for a BPO recruitment system. 
Extract the following information from the CV text below:
1. Full Name
2. Email
3. Phone
4. Education (Degree, University, Year)
5. Work History (Job Title, Company, Years)

Return the response as a valid JSON object with these exact keys: 
'name', 'email', 'phone', 'education', 'work_history'.

CV TEXT:
" . $mockCvText;

try {
    $gemini = new GeminiService();
    $parsedData = $gemini->generateText($prompt);
    
    echo "<h3>Gemini Response:</h3>";
    echo "<pre>";
    print_r($parsedData);
    echo "</pre>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}